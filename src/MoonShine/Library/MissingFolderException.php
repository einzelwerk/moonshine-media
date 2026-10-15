<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\MoonShine\Library;

use RuntimeException;

/**
 * A request named a folder and there is no such folder.
 *
 * It exists because the alternative is worse than an error. Reading an
 * unknown identifier as «no folder chosen» answers a request to move files
 * into a folder somebody had just deleted by moving them to the root, and the
 * editor is told the move succeeded. A refusal with a sentence sends them
 * back to the tree instead.
 *
 * It carries a `status()` like every refusal of the media package, so
 * {@see MediaRefusal} turns it into an answer with no line of its own.
 */
final class MissingFolderException extends RuntimeException
{
    public static function make(): self
    {
        return new self((string) __('moonshine-media::library.messages.folder_not_found'));
    }

    public function status(): int
    {
        return 404;
    }
}
