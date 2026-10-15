<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Exceptions;

use Ewk\Cms\Media\Models\CmsMedia;
use RuntimeException;

/**
 * A media record is refused deletion while usages point at it. This is the one
 * refusal of this package an editor reads, so its message is translated; the
 * uuid stays inside it, because the same text is what a developer finds in the
 * log.
 */
final class MediaInUseException extends RuntimeException
{
    public static function forMedia(CmsMedia $media, int $usages): self
    {
        return new self(self::message($media->uuid, $usages));
    }

    private static function message(string $uuid, int $usages): string
    {
        return MediaMessage::translate(
            'in_use',
            'Media [:uuid] cannot be deleted while it has :count active usage(s).',
            ['uuid' => $uuid, 'count' => $usages],
        );
    }
}
