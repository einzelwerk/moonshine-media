<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Exceptions;

use Ewk\Cms\Media\Models\CmsMedia;
use RuntimeException;

/**
 * Someone asked for a file or a folder that is not theirs to change. The check
 * lives in the action and not in a controller, so that no route can reach a
 * file by going around it.
 *
 * One sentence for both subjects on purpose: an editor gains nothing from
 * being told whether the refusal was about the file or about the folder it
 * sits in, and the panel shows the same banner either way.
 */
final class ForbiddenMediaActionException extends RuntimeException
{
    public function __construct(string $message, public readonly ?string $uuid = null)
    {
        parent::__construct($message);
    }

    public static function forMedia(CmsMedia $media): self
    {
        return new self(self::message(), $media->uuid);
    }

    /**
     * Folders are shared by everyone who works in the panel, so arranging
     * them is an administrator's job in this version.
     */
    public static function forFolders(): self
    {
        return new self(self::message());
    }

    public function status(): int
    {
        return 403;
    }

    private static function message(): string
    {
        return MediaMessage::translate('forbidden', 'You are not allowed to perform this action.');
    }
}
