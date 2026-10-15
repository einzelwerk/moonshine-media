<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Actions;

use Ewk\Cms\Media\Exceptions\FolderNotEmptyException;
use Ewk\Cms\Media\Exceptions\ForbiddenMediaActionException;
use Ewk\Cms\Media\Models\CmsMediaFolder;
use Ewk\Cms\Media\Support\Actor;

/**
 * Only an empty folder is deleted: deleting a whole catalog is refused because
 * a catalog may hold somebody else's files.
 */
final readonly class DeleteFolder
{
    /**
     * @throws ForbiddenMediaActionException
     * @throws FolderNotEmptyException
     */
    public function handle(CmsMediaFolder $folder, Actor $actor): void
    {
        if (! $actor->isAdmin()) {
            throw ForbiddenMediaActionException::forFolders();
        }

        if (! $folder->isEmpty()) {
            throw FolderNotEmptyException::forFolder($folder);
        }

        $folder->delete();
    }
}
