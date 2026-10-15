<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Exceptions;

/**
 * The file is larger than its kind allows, see `cms-media.kinds`. Both sizes
 * are in the sentence: a limit without the size that broke it leaves the
 * editor guessing how much has to go.
 */
final class FileTooLargeException extends UploadRejectedException
{
    public function status(): int
    {
        return 413;
    }

    public static function forSize(int $size, int $maxSize): self
    {
        $actual = self::megabytes($size);
        $max = self::megabytes($maxSize);

        return new self(self::translate(
            'too_large',
            ['size' => $actual, 'max' => $max],
            "The file is too large ({$actual} MB). The limit is {$max} MB.",
        ));
    }
}
