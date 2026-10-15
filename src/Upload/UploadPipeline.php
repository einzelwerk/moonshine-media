<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Upload;

use Ewk\Cms\Media\Contracts\VideoFramesContract;
use Ewk\Cms\Media\Enums\FileKind;
use Ewk\Cms\Media\Exceptions\FileTooLargeException;
use Ewk\Cms\Media\Exceptions\UnsupportedFileException;
use Ewk\Cms\Media\Support\KindRegistry;
use Ewk\Cms\Media\Support\KindRule;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * The checks every file passes before the library keeps it, and the work each
 * kind needs afterwards. One pipeline for the upload and for the replacement,
 * because a second path is a rule that can be walked around, which is how
 * most upload incidents happen.
 *
 * In order: the extension of the client name says which kind to measure
 * against, the bytes have to agree with it, the size has to fit the limit of
 * that kind, and only then is anything done with the file.
 */
final class UploadPipeline
{
    /**
     * What `finfo` may report for a vector. The magic database answers by how
     * the file opens, so an SVG is XML, plain text or even HTML depending on
     * the first line. None of these is enough to accept a file: they only
     * decide whether it is worth parsing the document, which is the real
     * check, see `SvgSanitizer::looksLikeSvg()`.
     */
    private const array SVG_MIMES = [
        'image/svg+xml',
        'image/svg',
        'text/xml',
        'application/xml',
        'text/plain',
        'text/html',
    ];

    /**
     * The one thing this class knows about formats: a name ending in `.png`
     * has to be a PNG. The allowlist itself stays in the configuration; this
     * only keeps a JPEG from being stored as somebody's PNG, which is what an
     * extension that nobody verified is worth.
     */
    private const array IMAGE_MIME_BY_EXTENSION = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
    ];

    /**
     * The extension a file of the other kinds is stored under, decided by the
     * type of its bytes. A name is not evidence: an MP3 saved as `.wav`
     * would otherwise be served as a WAV and refuse to play in half the
     * browsers, and the address on a disk is written once and cached for a
     * year.
     *
     * The two container types of the legacy office formats are missing on
     * purpose. `application/x-ole-storage` and `application/vnd.ms-office`
     * are what `finfo` reports for `.doc`, `.xls` and `.ppt` alike, so they
     * say nothing about which of the three a file is and the extension of the
     * name, already measured against the allowlist, stays.
     */
    private const array EXTENSION_BY_MIME = [
        'video/mp4' => 'mp4',
        'video/webm' => 'webm',
        'audio/mpeg' => 'mp3',
        'audio/ogg' => 'ogg',
        'audio/wav' => 'wav',
        'audio/x-wav' => 'wav',
        'audio/mp4' => 'm4a',
        'audio/x-m4a' => 'm4a',
        'application/pdf' => 'pdf',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.ms-powerpoint' => 'ppt',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
    ];

    public function __construct(
        private readonly KindRegistry $kinds,
        private readonly ImageProcessor $images,
        private readonly SvgSanitizer $svg,
        private readonly VideoFramesContract $frames,
    ) {
    }

    /**
     * Steps one to three: the extension, the size and the bytes.
     *
     * The size is measured before the bytes are looked at. Which check comes
     * first changes nothing about what a file has to satisfy, only what it
     * costs, and the cost is real: deciding the type of a vector means reading
     * the whole file and parsing it as a document, and a hundred megabytes of
     * text named `.svg` would be read and parsed before a two megabyte limit
     * refused it. Measuring first costs nothing and refuses the same files.
     *
     * @param  list<FileKind>|null  $allowedKinds  null: every kind the configuration describes
     */
    public function inspect(UploadedFile $file, ?array $allowedKinds = null): InspectedUpload
    {
        $allowed = $this->allowed($allowedKinds);
        $extension = strtolower($file->getClientOriginalExtension());
        $kind = $extension === '' ? null : $this->kinds->kindForExtension($extension);

        if (! $kind instanceof FileKind) {
            throw UnsupportedFileException::forExtension($this->extensionsOf($allowed));
        }

        // A kind the library knows and this place does not take: the caller
        // is told which kind it was, so that a replacement can say «the same
        // type» instead of listing every format again.
        if (! in_array($kind, $allowed, true)) {
            throw UnsupportedFileException::forExtension($this->extensionsOf($allowed), $kind);
        }

        $rule = $this->kinds->get($kind);
        $path = self::path($file);

        // A size that cannot be read is treated as the largest one there is:
        // a file nobody can measure is not one to hand to a decoder.
        $size = $file->getSize();
        $size = is_int($size) ? $size : PHP_INT_MAX;

        if ($size > $rule->maxSize) {
            throw FileTooLargeException::forSize($size, $rule->maxSize);
        }

        $mime = $kind === FileKind::VECTOR
            ? $this->vectorMime($path)
            : $this->bytesMime($path, $rule, $extension);

        return new InspectedUpload($kind, $mime, self::storedExtension($kind, $mime, $extension), $size);
    }

    /**
     * Step five: the bytes as they are going to be stored.
     */
    public function prepare(UploadedFile $file, InspectedUpload $inspected): PreparedFile
    {
        $path = self::path($file);

        return match ($inspected->kind) {
            FileKind::IMAGE => $this->images->process($path),
            FileKind::VECTOR => $this->vector($path),
            FileKind::VIDEO => $this->video($path, $inspected),
            default => PreparedFile::ofPath($inspected->kind, $path, $inspected->mime, $inspected->extension),
        };
    }

    /**
     * A video is stored as it came, with the size it is shown at where the
     * installation can read it. A size nobody could read is unknown and not
     * a reason to refuse a file that already passed every check.
     */
    private function video(string $path, InspectedUpload $inspected): PreparedFile
    {
        $dimensions = null;

        if ($this->frames->available()) {
            try {
                $dimensions = $this->frames->dimensions($path);
            } catch (Throwable) {
                $dimensions = null;
            }
        }

        return PreparedFile::ofPath(
            FileKind::VIDEO,
            $path,
            $inspected->mime,
            $inspected->extension,
            $dimensions[0] ?? null,
            $dimensions[1] ?? null,
        );
    }

    private function vector(string $path): PreparedFile
    {
        $clean = $this->svg->sanitize(self::read($path));
        [$width, $height] = $this->svg->dimensions($clean);

        return PreparedFile::ofBytes(FileKind::VECTOR, $clean, 'image/svg+xml', 'svg', $width, $height);
    }

    private function vectorMime(string $path): string
    {
        if (! in_array(self::mime($path), self::SVG_MIMES, true)) {
            throw UnsupportedFileException::forContent();
        }

        if (! $this->svg->looksLikeSvg(self::read($path))) {
            throw UnsupportedFileException::forSvg();
        }

        // Whatever the magic database called it, what is stored is a vector
        // and it is served as one.
        return 'image/svg+xml';
    }

    /**
     * The type of everything that is not a vector, measured against the rule
     * of its kind.
     */
    private function bytesMime(string $path, KindRule $rule, string $extension): string
    {
        $mime = self::mime($path);

        // «Anything at all» is not a type. It is what `finfo` says when it
        // recognised nothing, and it must never match a rule, whatever an
        // installation happens to have listed.
        if ($mime === 'application/octet-stream' || ! $rule->allowsMime($mime)) {
            throw UnsupportedFileException::forContent();
        }

        $expected = self::IMAGE_MIME_BY_EXTENSION[$extension] ?? null;

        if ($rule->kind === FileKind::IMAGE && $expected !== null && $expected !== $mime) {
            throw UnsupportedFileException::forContent();
        }

        return $mime;
    }

    /**
     * The extension the server writes the file under.
     *
     * A vector is always an `svg` and a picture is whatever the encoder makes
     * of it, see `ImageProcessor`. For the rest the type of the bytes decides
     * where it can, and the name is kept only where the type is a container
     * that several formats share.
     */
    private static function storedExtension(FileKind $kind, string $mime, string $clientExtension): string
    {
        return match ($kind) {
            FileKind::VECTOR => 'svg',
            FileKind::IMAGE => $clientExtension === 'jpeg' ? 'jpg' : $clientExtension,
            default => self::EXTENSION_BY_MIME[$mime] ?? $clientExtension,
        };
    }

    /**
     * @param  list<FileKind>|null  $requested
     * @return list<FileKind>
     */
    private function allowed(?array $requested): array
    {
        $configured = array_map(
            static fn (KindRule $rule): FileKind => $rule->kind,
            $this->kinds->all(),
        );

        if ($requested === null) {
            return $configured;
        }

        $allowed = array_values(array_filter(
            $configured,
            static fn (FileKind $kind): bool => in_array($kind, $requested, true),
        ));

        if ($allowed === []) {
            throw new InvalidArgumentException('None of the requested media kinds is configured in [cms-media.kinds].');
        }

        return $allowed;
    }

    /**
     * The formats named in the refusal. `jpeg` is dropped next to `jpg`: it
     * is the same format twice and reads like a mistake in a list an editor
     * is shown.
     *
     * @param  list<FileKind>  $kinds
     * @return list<string>
     */
    private function extensionsOf(array $kinds): array
    {
        $extensions = [];

        foreach ($kinds as $kind) {
            foreach ($this->kinds->get($kind)->extensions as $extension) {
                $extensions[$extension] = true;
            }
        }

        if (isset($extensions['jpg'])) {
            unset($extensions['jpeg']);
        }

        return array_keys($extensions);
    }

    /**
     * The digest of the bytes that were uploaded.
     * It is what the offer to reuse an existing record is built on.
     *
     * A temporary file that cannot be read leaves the column empty instead of
     * failing the upload: the digest is a convenience of the library, not a
     * condition for storing a file.
     */
    public static function checksum(UploadedFile $file): ?string
    {
        $path = $file->getRealPath();

        if ($path === false || ! is_readable($path)) {
            return null;
        }

        $digest = hash_file('sha256', $path);

        return $digest === false ? null : $digest;
    }

    /**
     * The name as it arrived, kept for the details panel and for search. It
     * is text and nothing else: the address on the disk is built from the
     * uuid, so this never reaches a file system.
     */
    public static function originalName(string $clientName): string
    {
        $name = preg_replace('/[\x00-\x1F\x7F]+/', '', $clientName) ?? $clientName;

        return mb_substr(trim($name), 0, 255);
    }

    private static function path(UploadedFile $file): string
    {
        $path = $file->getRealPath();

        if ($path === false || ! is_readable($path)) {
            throw new RuntimeException('The uploaded file is no longer readable.');
        }

        return $path;
    }

    private static function read(string $path): string
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("The uploaded file [{$path}] cannot be read.");
        }

        return $contents;
    }

    /**
     * The type of the bytes, read with `finfo` from the file on disk.
     *
     * It is not asked of the `UploadedFile`: the type that object reports is
     * the one the browser sent for a real request and the one the file name
     * suggests for a fake upload in a test, and neither has anything to do
     * with what is inside the file.
     */
    private static function mime(string $path): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            return 'application/octet-stream';
        }

        $mime = finfo_file($finfo, $path);
        finfo_close($finfo);

        return is_string($mime) ? KindRegistry::normalizeMime($mime) : 'application/octet-stream';
    }
}
