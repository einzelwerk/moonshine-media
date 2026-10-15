<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Exceptions;

use Ewk\Cms\Media\Models\CmsMediaFolder;
use RuntimeException;

/**
 * Only an empty folder is deleted: a recursive delete would take files
 * someone else put there.
 */
final class FolderNotEmptyException extends RuntimeException
{
    public static function forFolder(CmsMediaFolder $folder): self
    {
        return new self(MediaMessage::translate(
            'folder_not_empty',
            'The folder :name is not empty and cannot be deleted.',
            ['name' => $folder->name],
        ));
    }

    public function status(): int
    {
        return 409;
    }
}
