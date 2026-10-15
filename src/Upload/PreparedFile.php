<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Upload;

use Ewk\Cms\Media\Enums\FileKind;
use RuntimeException;

/**
 * The bytes as they are going to be stored, after the kind had its say: a
 * re-encoded picture, a cleaned up vector, or the file itself for everything
 * the library does not touch.
 *
 * A picture and a vector are held in memory, because they were just built
 * there. A video is not: a hundred megabytes of it are handed to the disk as
 * a stream from the temporary file of the request.
 */
final readonly class PreparedFile
{
    private function __construct(
        public FileKind $kind,
        public string $mime,
        public string $extension,
        public ?int $width,
        public ?int $height,
        public int $size,
        private ?string $binary,
        private ?string $path,
    ) {
    }

    public static function ofBytes(
        FileKind $kind,
        string $binary,
        string $mime,
        string $extension,
        ?int $width = null,
        ?int $height = null,
    ): self {
        return new self(
            kind: $kind,
            mime: $mime,
            extension: self::normalize($extension),
            width: $width,
            height: $height,
            size: strlen($binary),
            binary: $binary,
            path: null,
        );
    }

    public static function ofPath(
        FileKind $kind,
        string $path,
        string $mime,
        string $extension,
        ?int $width = null,
        ?int $height = null,
    ): self {
        $size = @filesize($path);

        return new self(
            kind: $kind,
            mime: $mime,
            extension: self::normalize($extension),
            width: $width,
            height: $height,
            size: $size === false ? 0 : $size,
            binary: null,
            path: $path,
        );
    }

    /**
     * What is handed to the disk: a string for what was built in memory, an
     * open stream for what is still a file. The caller closes the stream.
     *
     * @return string|resource
     */
    public function payload()
    {
        if ($this->binary !== null) {
            return $this->binary;
        }

        $stream = @fopen((string) $this->path, 'rb');

        if ($stream === false) {
            throw new RuntimeException("The prepared file [{$this->path}] cannot be read.");
        }

        return $stream;
    }

    /**
     * The bytes themselves. Only the tests and the checks that look inside a
     * stored file need them; the upload itself goes through `payload()`.
     */
    public function contents(): string
    {
        if ($this->binary !== null) {
            return $this->binary;
        }

        $contents = @file_get_contents((string) $this->path);

        if ($contents === false) {
            throw new RuntimeException("The prepared file [{$this->path}] cannot be read.");
        }

        return $contents;
    }

    /**
     * `jpeg` and `jpg` are one format and one address on a disk.
     */
    private static function normalize(string $extension): string
    {
        $extension = strtolower(ltrim($extension, '.'));

        return $extension === 'jpeg' ? 'jpg' : $extension;
    }
}
