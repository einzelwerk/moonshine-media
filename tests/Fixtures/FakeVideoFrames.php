<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Tests\Fixtures;

use Ewk\Cms\Media\Contracts\VideoFramesContract;
use RuntimeException;

/**
 * Frames without ffmpeg: a generated picture and a size the test decides,
 * and the sources it was asked about, so a test can see what the driver
 * handed over.
 */
final class FakeVideoFrames implements VideoFramesContract
{
    /** @var list<string> */
    public array $sources = [];

    /**
     * @param  array{0: int, 1: int}|null  $dimensions
     */
    public function __construct(
        private readonly bool $available = true,
        private readonly ?array $dimensions = [1080, 1920],
        private readonly int $frameWidth = 64,
        private readonly int $frameHeight = 48,
        private readonly bool $broken = false,
    ) {
    }

    public function available(): bool
    {
        return $this->available;
    }

    public function frame(string $source): string
    {
        $this->sources[] = $source;

        if ($this->broken) {
            throw new RuntimeException('ffmpeg could not read the video.');
        }

        $image = imagecreatetruecolor($this->frameWidth, $this->frameHeight);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    public function dimensions(string $path): ?array
    {
        if ($this->broken) {
            throw new RuntimeException('ffprobe could not read the video.');
        }

        return $this->dimensions;
    }

    /**
     * The smallest file `finfo` calls `video/mp4`: a `ftyp` box and nothing
     * to play. Enough for the checks of an upload, and the fake never reads it.
     */
    public static function mp4(): string
    {
        return pack('N', 24).'ftypisom'.pack('N', 512).'isomiso2'.pack('N', 8).'free';
    }
}
