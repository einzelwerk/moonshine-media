<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Video;

use Ewk\Cms\Media\Contracts\VideoFramesContract;
use Illuminate\Contracts\Config\Repository;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Frames and sizes read with `ffmpeg` and `ffprobe`, see
 * `cms-media.video`.
 *
 * A video is a file somebody uploaded, and a decoder is the largest surface
 * a server offers to one. Both tools are therefore run without a shell, with
 * a limit of time, and allowed the protocols the source needs and no other:
 * a local file opens local files only, and a file in a bucket, which the
 * driver names by an address of its own, adds the web and nothing more. A
 * path is passed with the `file:` prefix, so a name that looks like a
 * protocol is never read as one.
 */
final class FfmpegVideoFrames implements VideoFramesContract
{
    private ?bool $available = null;

    public function __construct(private readonly Repository $config)
    {
    }

    public function available(): bool
    {
        if ($this->available !== null) {
            return $this->available;
        }

        if ($this->config->get('cms-media.video.frames', false) !== true) {
            return $this->available = false;
        }

        // An installation that turned frames on and forgot the tools gets a
        // video without a preview, as before, and not a failed upload.
        return $this->available = self::executable($this->ffmpeg()) && self::executable($this->ffprobe());
    }

    public function frame(string $source): string
    {
        $frame = $this->cut($source, $this->moment());

        // A clip shorter than the moment asked for has no frame there, and
        // ffmpeg answers that with nothing at all rather than with an error:
        // the first frame is the one every video has.
        if ($frame === '' && $this->moment() > 0.0) {
            $frame = $this->cut($source, 0.0);
        }

        if ($frame === '') {
            throw new RuntimeException('No frame could be read from the video.');
        }

        return $frame;
    }

    public function dimensions(string $path): ?array
    {
        $process = new Process([
            $this->ffprobe(),
            '-v', 'error',
            '-protocol_whitelist', 'file',
            '-select_streams', 'v:0',
            '-show_entries', 'stream=width,height:stream_side_data=rotation:stream_tags=rotate',
            '-of', 'json',
            'file:'.$path,
        ]);
        $process->setTimeout($this->timeout());
        $process->run();

        if (! $process->isSuccessful()) {
            return null;
        }

        $probe = json_decode($process->getOutput(), true);
        $stream = is_array($probe) && is_array($probe['streams'] ?? null) ? ($probe['streams'][0] ?? null) : null;

        if (! is_array($stream)) {
            return null;
        }

        $width = $stream['width'] ?? null;
        $height = $stream['height'] ?? null;

        if (! is_int($width) || ! is_int($height) || $width <= 0 || $height <= 0) {
            return null;
        }

        // A phone stores a portrait clip as a landscape one with a note to
        // turn it; the frame ffmpeg cuts is already turned, so the size has
        // to be as well.
        return abs(self::rotation($stream)) % 180 === 90 ? [$height, $width] : [$width, $height];
    }

    private function cut(string $source, float $seconds): string
    {
        $remote = preg_match('#^https?://#i', $source) === 1;

        $process = new Process([
            $this->ffmpeg(),
            '-hide_banner',
            '-loglevel', 'error',
            '-nostdin',
            '-protocol_whitelist', $remote ? 'http,https,tcp,tls,crypto' : 'file',
            // Seeking before the input is what makes a bucket cheap: the
            // demuxer jumps by the index instead of reading up to the moment.
            '-ss', sprintf('%.3F', $seconds),
            '-i', $remote ? $source : 'file:'.$source,
            '-frames:v', '1',
            '-f', 'image2pipe',
            '-c:v', 'png',
            'pipe:1',
        ]);
        $process->setTimeout($this->timeout());
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('ffmpeg could not read the video: '.trim($process->getErrorOutput()));
        }

        return $process->getOutput();
    }

    /**
     * @param  array<array-key, mixed>  $stream
     */
    private static function rotation(array $stream): int
    {
        $sideData = $stream['side_data_list'] ?? null;

        if (is_array($sideData)) {
            foreach ($sideData as $entry) {
                if (is_array($entry) && is_numeric($entry['rotation'] ?? null)) {
                    return (int) $entry['rotation'];
                }
            }
        }

        $tags = $stream['tags'] ?? null;

        return is_array($tags) && is_numeric($tags['rotate'] ?? null) ? (int) $tags['rotate'] : 0;
    }

    private static function executable(string $binary): bool
    {
        if (str_contains($binary, DIRECTORY_SEPARATOR)) {
            return is_executable($binary);
        }

        return (new ExecutableFinder())->find($binary) !== null;
    }

    private function ffmpeg(): string
    {
        return $this->string('cms-media.video.ffmpeg', 'ffmpeg');
    }

    private function ffprobe(): string
    {
        return $this->string('cms-media.video.ffprobe', 'ffprobe');
    }

    private function moment(): float
    {
        $seconds = $this->config->get('cms-media.video.frame_at', 1.0);

        return is_numeric($seconds) ? max(0.0, (float) $seconds) : 1.0;
    }

    private function timeout(): float
    {
        $seconds = $this->config->get('cms-media.video.timeout', 60);

        return is_numeric($seconds) && (float) $seconds > 0 ? (float) $seconds : 60.0;
    }

    private function string(string $key, string $default): string
    {
        $value = $this->config->get($key, $default);

        return is_string($value) && trim($value) !== '' ? trim($value) : $default;
    }
}
