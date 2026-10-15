<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Drivers;

use Ewk\Cms\Media\Contracts\MediaDriverContract;
use Ewk\Cms\Media\Contracts\VideoFramesContract;
use Ewk\Cms\Media\Enums\FileKind;
use Ewk\Cms\Media\Models\CmsMedia;
use Ewk\Cms\Media\Support\FitMode;
use Ewk\Cms\Media\Support\Preset;
use Ewk\Cms\Media\Support\StoragePath;
use Ewk\Cms\Media\Support\StoredFile;
use Ewk\Cms\Media\Upload\ImageManagerFactory;
use Ewk\Cms\Media\Upload\PreparedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use RuntimeException;

/**
 * Single first-party driver: a plain disk plus our own conversions. A driver
 * backed by another library or by an image proxy is a matter of a binding of
 * {@see \Ewk\Cms\Media\Contracts\MediaDriverContract}.
 *
 * Every write carries the headers the file will be served with for a year:
 * the address of a version never changes its contents, so the cache is told
 * so, and the type is written down instead of being sniffed by a browser. No
 * visibility is passed: an R2 bucket has no ACLs, and asking for one is an
 * error there rather than a setting that is ignored.
 */
final class LocalDriver implements MediaDriverContract
{
    /**
     * A version directory is written once and never rewritten, so the address
     * can promise what an address rarely can.
     */
    private const string CACHE_CONTROL = 'public, max-age=31536000, immutable';

    public function __construct(
        private readonly ImageManagerFactory $managers,
        private readonly VideoFramesContract $frames,
    ) {
    }

    public function store(PreparedFile $file, string $disk, string $uuid, int $version, ?string $name = null): StoredFile
    {
        $path = StoragePath::original($uuid, $version, $file->extension);

        $this->put($disk, $path, $file->payload(), [
            'ContentType' => $file->mime,
            'CacheControl' => self::CACHE_CONTROL,
            'ContentDisposition' => self::disposition($file->kind, $file->mime, $name, $file->extension),
        ]);

        return new StoredFile(
            disk: $disk,
            path: $path,
            mime: $file->mime,
            size: $file->size,
            width: $file->width,
            height: $file->height,
            format: $file->extension,
        );
    }

    public function variant(CmsMedia $media, Preset $preset): StoredFile
    {
        $image = $this->manager()->read(
            $media->kind === FileKind::VIDEO ? $this->frame($media) : $this->original($media),
        );

        match ($preset->fit) {
            FitMode::COVER => $image->cover($preset->width, $preset->height),
            FitMode::CONTAIN => $image->contain($preset->width, $preset->height),
            FitMode::SCALE_DOWN => $image->scaleDown($preset->width, $preset->height),
        };

        // A frame has no format of its own to keep: a preset that names none
        // makes it a JPEG, as a photograph would be.
        $format = $preset->format ?? ($media->kind === FileKind::VIDEO ? 'jpg' : pathinfo($media->path, PATHINFO_EXTENSION));
        $encoded = $image->encodeByExtension($format, quality: $preset->quality);

        $variantPath = StoragePath::variant($media->uuid, $media->version, $preset->name, $format);

        $this->put($media->disk, $variantPath, (string) $encoded, [
            'ContentType' => $encoded->mimetype(),
            'CacheControl' => self::CACHE_CONTROL,
        ]);

        return new StoredFile(
            disk: $media->disk,
            path: $variantPath,
            mime: $encoded->mimetype(),
            size: strlen((string) $encoded),
            width: $image->width(),
            height: $image->height(),
            format: $format,
        );
    }

    public function delete(string $disk, string $path): void
    {
        if ($path === '') {
            return;
        }

        Storage::disk($disk)->delete($path);
    }

    public function deleteDirectory(string $disk, string $directory): void
    {
        if ($directory === '') {
            return;
        }

        Storage::disk($disk)->deleteDirectory($directory);
    }

    private function original(CmsMedia $media): string
    {
        // Read from the address the row holds and never from one built again
        // out of the uuid: a record written before the versioned layout has
        // no `v1` in its path, and its variants still have to be generated.
        $binary = Storage::disk($media->disk)->get($media->path);

        if ($binary === null) {
            throw self::missing($media);
        }

        return $binary;
    }

    /**
     * A frame of a video, cut where the file lies when the disk is a folder
     * of this server.
     *
     * A disk elsewhere — a bucket — is handed to ffmpeg as an address that
     * expires in a few minutes, and not copied here first: with the moment
     * of the frame known before the file is opened, ffmpeg asks for the index
     * of the file and the few seconds around that moment with range requests,
     * a few megabytes of a hundred. A copy would fill the temporary folder of
     * a container with one video per preset in line.
     */
    private function frame(CmsMedia $media): string
    {
        $disk = Storage::disk($media->disk);
        $local = $disk->path($media->path);

        if (is_file($local)) {
            return $this->frames->frame($local);
        }

        if (! $disk->exists($media->path)) {
            throw self::missing($media);
        }

        $url = $disk->providesTemporaryUrls()
            ? $disk->temporaryUrl($media->path, Carbon::now()->addMinutes(10))
            : $disk->url($media->path);

        return $this->frames->frame($url);
    }

    private static function missing(CmsMedia $media): RuntimeException
    {
        return new RuntimeException("Original file for media [{$media->uuid}] is missing on disk [{$media->disk}].");
    }

    /**
     * @param  string|resource  $payload
     * @param  array<string, string>  $options
     */
    private function put(string $disk, string $path, $payload, array $options): void
    {
        try {
            $written = Storage::disk($disk)->put($path, $payload, $options);
        } finally {
            if (is_resource($payload)) {
                fclose($payload);
            }
        }

        if ($written === false) {
            throw new RuntimeException("Writing [{$path}] to disk [{$disk}] failed.");
        }
    }

    /**
     * What a browser does with the file. Everything that is shown on a page
     * is shown, and a document that is not a PDF is downloaded.
     *
     * The name is encoded by RFC 5987, so that a Russian file name survives a
     * header, and it is the only part of the answer that the editor wrote.
     */
    private static function disposition(FileKind $kind, string $mime, ?string $name, string $extension): string
    {
        $inline = $kind !== FileKind::DOCUMENT || $mime === 'application/pdf';
        $filename = self::downloadName($name, $extension);

        // Both spellings, in the order RFC 6266 asks for: a client that reads
        // only the plain parameter gets a name it can write to a disk, and one
        // that understands the encoded parameter prefers it and gets the name
        // the editor actually typed.
        return sprintf(
            '%s; filename="%s"; filename*=UTF-8\'\'%s',
            $inline ? 'inline' : 'attachment',
            self::asciiName($filename),
            rawurlencode($filename),
        );
    }

    /**
     * The same name with nothing in it that a header cannot carry: letters of
     * another alphabet are transliterated, what does not transliterate is
     * dropped, and a quote, a backslash, a semicolon or a comma becomes an
     * underscore. The last three are legal inside a quoted string and are
     * still taken out: a client that splits a header on them before it reads
     * the quotes would otherwise see a parameter that is not there.
     */
    private static function asciiName(string $filename): string
    {
        $ascii = preg_replace('/[^\x20-\x7E]+/', '', Str::ascii($filename)) ?? '';
        $ascii = str_replace(['"', '\\', ';', ','], '_', $ascii);
        $ascii = trim(preg_replace('/\s+/', ' ', $ascii) ?? $ascii);

        return $ascii === '' || str_starts_with($ascii, '.') ? 'file'.$ascii : $ascii;
    }

    private static function downloadName(?string $name, string $extension): string
    {
        $name = trim(preg_replace('/[\x00-\x1F\x7F\/\\\\]+/', ' ', $name ?? '') ?? '');
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? $name);

        if ($name === '') {
            $name = 'file';
        }

        return mb_substr($name, 0, 100).'.'.$extension;
    }

    private function manager(): ImageManager
    {
        return $this->managers->make();
    }
}
