<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Exceptions;

/**
 * The picture holds more pixels than `cms-media.image.max_megapixels` allows.
 * It is measured from the header, before any decoder is handed the file: a
 * small file that declares forty thousand pixels a side is the classic way to
 * take a server down with an upload.
 */
final class ImageTooLargeException extends UploadRejectedException
{
    public function status(): int
    {
        return 413;
    }

    public static function forPixels(int $width, int $height, int $maxMegapixels): self
    {
        $megapixels = self::number($width * $height / 1_000_000);
        $max = self::number((float) $maxMegapixels);

        return new self(self::translate(
            'image_too_large',
            ['megapixels' => $megapixels, 'max' => $max],
            "The picture is too big: {$megapixels} megapixels. The limit is {$max}.",
        ));
    }
}
