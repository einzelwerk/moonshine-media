<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Upload;

use Ewk\Cms\Media\Enums\FileKind;
use Ewk\Cms\Media\Exceptions\CorruptImageException;
use Ewk\Cms\Media\Exceptions\ImageTooLargeException;
use Ewk\Cms\Media\Exceptions\UnsupportedFileException;
use Illuminate\Support\Facades\Config;
use Intervention\Image\Interfaces\ImageInterface;
use Throwable;

/**
 * What happens to a raster picture on its way into the library: the header is
 * measured first, then the picture is decoded, rotated by its EXIF, scaled
 * down to the long side of the configuration and written out again in the same
 * format.
 *
 * Re-encoding is not a convenience. It is what makes the stored file a
 * picture and nothing else: the metadata goes, and so does whatever was
 * appended after the last byte of the image, which is how a GIF with a PHP
 * script glued to its tail stops being one.
 *
 * The two files that are kept byte for byte are an animated GIF, which a
 * single frame decoder would flatten, and a vector, which is not handled here
 * at all. Both are stored only after they were proved to be what they claim.
 */
final class ImageProcessor
{
    /** The formats the encoder is given a quality for; the others have none. */
    private const array LOSSY = ['jpg', 'webp', 'avif'];

    public function __construct(private readonly ImageManagerFactory $managers)
    {
    }

    public function process(string $path): PreparedFile
    {
        $header = @getimagesize($path);

        if ($header === false) {
            throw CorruptImageException::make();
        }

        [$width, $height] = [(int) $header[0], (int) $header[1]];

        $maxMegapixels = Config::integer('cms-media.image.max_megapixels', 50);

        // Before a decoder sees the file: a header may declare forty thousand
        // pixels a side in a few hundred bytes, and allocating that is the
        // whole of the attack.
        if ($maxMegapixels > 0 && $width * $height > $maxMegapixels * 1_000_000) {
            throw ImageTooLargeException::forPixels($width, $height, $maxMegapixels);
        }

        $extension = self::extensionFor((int) $header[2]);

        if ($extension === null) {
            throw UnsupportedFileException::forContent();
        }

        if (! $this->managers->supports($extension)) {
            throw UnsupportedFileException::forAvif();
        }

        $binary = @file_get_contents($path);

        if ($binary === false) {
            throw CorruptImageException::make();
        }

        if ($extension === 'gif') {
            [$frames, $end] = self::scanGif($binary);

            if ($frames > 1) {
                // Trimmed at the trailer: an animation is the one picture the
                // library keeps byte for byte, so whatever was glued behind
                // the end of the file is cut here instead of being stored.
                return $this->keepAnimation(substr($binary, 0, $end), $width, $height);
            }
        }

        return $this->reencode($binary, $extension);
    }

    /**
     * An animated GIF keeps its bytes, because there is nothing in the image
     * that re-encodes one: a decoder reads the first frame and would store a
     * still picture instead of the file the editor uploaded. It is still
     * decoded once, so that the bytes are proved to be a GIF and not a
     * container for something else.
     */
    private function keepAnimation(string $binary, int $width, int $height): PreparedFile
    {
        try {
            $this->managers->make()->read($binary);
        } catch (Throwable) {
            throw CorruptImageException::make();
        }

        return PreparedFile::ofBytes(FileKind::IMAGE, $binary, 'image/gif', 'gif', $width, $height);
    }

    private function reencode(string $binary, string $extension): PreparedFile
    {
        $maxDimension = Config::integer('cms-media.image.max_dimension', 2560);
        $quality = Config::integer('cms-media.image.quality', 90);

        try {
            $image = $this->managers->make()->read($binary);
            $image->orient();

            if ($maxDimension > 0) {
                $image->scaleDown($maxDimension, $maxDimension);
            }

            self::stripMetadata($image);

            $encoded = in_array($extension, self::LOSSY, true)
                ? $image->encodeByExtension($extension, quality: $quality)
                : $image->encodeByExtension($extension);
        } catch (Throwable) {
            throw CorruptImageException::make();
        }

        return PreparedFile::ofBytes(
            FileKind::IMAGE,
            (string) $encoded,
            $encoded->mimetype(),
            $extension,
            $image->width(),
            $image->height(),
        );
    }

    /**
     * Takes the metadata off the picture before it is encoded.
     *
     * GD keeps none of it to begin with: it decodes into a canvas of pixels
     * and writes a file that has nothing else in it. ImageMagick does keep
     * it, and an encoder of the image asks it to, so the EXIF of a phone
     * (the camera, the software and the place the picture was taken) would
     * survive an upload and be served from the site.
     *
     * The backend is asked through its own object and by the name of the
     * method, because this package is installed on builds that have no
     * ImageMagick at all, and a type of that extension may not be named here.
     */
    private static function stripMetadata(ImageInterface $image): void
    {
        $native = $image->core()->native();

        if (is_object($native) && method_exists($native, 'stripImage')) {
            $native->stripImage();
        }
    }

    /**
     * The extension the server stores the picture under, decided by the type
     * the header really is and never by the name the client sent.
     */
    private static function extensionFor(int $type): ?string
    {
        return match ($type) {
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_PNG => 'png',
            IMAGETYPE_GIF => 'gif',
            IMAGETYPE_WEBP => 'webp',
            IMAGETYPE_AVIF => 'avif',
            default => null,
        };
    }

    /**
     * Walks the blocks of a GIF and answers two things: how many frames it
     * holds and where the file ends.
     *
     * The blocks are walked instead of the usual search for the four bytes of
     * a graphic control extension, because that search reads pixel data as
     * well and answers by luck. The end of the file matters for the same
     * reason the frames do: an animation is stored as it arrived, so the
     * trailer is where the stored bytes stop.
     *
     * @return array{0: int, 1: int}  frames, offset just past the trailer
     */
    private static function scanGif(string $binary): array
    {
        $length = strlen($binary);

        if ($length < 14 || ! str_starts_with($binary, 'GIF')) {
            return [0, $length];
        }

        $packed = ord($binary[10]);
        $offset = 13;

        // The global colour table, when the screen descriptor announces one.
        if (($packed & 0x80) !== 0) {
            $offset += 3 * (2 ** (($packed & 0x07) + 1));
        }

        $frames = 0;

        while ($offset < $length) {
            $block = ord($binary[$offset]);

            if ($block === 0x3B) {
                return [$frames, $offset + 1];
            }

            if ($block === 0x21) {
                // An extension: the separator, the label, then sub-blocks.
                $offset = self::skipSubBlocks($binary, $offset + 2);

                continue;
            }

            if ($block !== 0x2C) {
                // Not a block of a GIF at all: the file ends where it stopped
                // making sense.
                return [$frames, $offset];
            }

            $frames++;

            if ($offset + 10 > $length) {
                return [$frames, $length];
            }

            $local = ord($binary[$offset + 9]);
            $offset += 10;

            if (($local & 0x80) !== 0) {
                $offset += 3 * (2 ** (($local & 0x07) + 1));
            }

            // The minimum code size, then the compressed sub-blocks.
            $offset = self::skipSubBlocks($binary, $offset + 1);
        }

        return [$frames, $length];
    }

    private static function skipSubBlocks(string $binary, int $offset): int
    {
        $length = strlen($binary);

        while ($offset < $length) {
            $size = ord($binary[$offset]);
            $offset += $size + 1;

            if ($size === 0) {
                break;
            }
        }

        return min($offset, $length);
    }
}
