<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Exceptions;

use Ewk\Cms\Media\Models\CmsMediaFolder;
use RuntimeException;

/**
 * A folder cannot be moved into itself or into its own subtree: the branch
 * would lose its way back to the root and disappear from the tree.
 */
final class InvalidFolderMoveException extends RuntimeException
{
    public static function forFolder(CmsMediaFolder $folder): self
    {
        return new self(MediaMessage::translate(
            'invalid_folder_move',
            'The folder :name cannot be moved into itself or into a folder inside it.',
            ['name' => $folder->name],
        ));
    }

    public function status(): int
    {
        return 422;
    }
}
