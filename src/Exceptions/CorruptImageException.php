<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Exceptions;

/**
 * The bytes pass for a picture and no decoder can open them.
 */
final class CorruptImageException extends UploadRejectedException
{
    public static function make(): self
    {
        return new self(self::translate(
            'corrupt_image',
            [],
            'The picture could not be opened. The file may be damaged.',
        ));
    }
}
