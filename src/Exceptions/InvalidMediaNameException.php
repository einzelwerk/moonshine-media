<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Exceptions;

use RuntimeException;

/**
 * A name that is empty after trimming, or longer than the column. The same
 * refusal covers a file and a folder: both carry one name the editor typed.
 */
final class InvalidMediaNameException extends RuntimeException
{
    public const int MAX_LENGTH = 255;

    public static function make(): self
    {
        return new self(MediaMessage::translate(
            'invalid_name',
            'The name must not be empty and must not exceed :max characters.',
            ['max' => self::MAX_LENGTH],
        ));
    }

    public function status(): int
    {
        return 422;
    }
}
