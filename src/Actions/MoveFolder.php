<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Actions;

use Ewk\Cms\Media\Exceptions\DuplicateFolderNameException;
use Ewk\Cms\Media\Exceptions\ForbiddenMediaActionException;
use Ewk\Cms\Media\Exceptions\InvalidFolderMoveException;
use Ewk\Cms\Media\Models\CmsMediaFolder;
use Ewk\Cms\Media\Support\Actor;
use Ewk\Cms\Media\Support\FolderSiblings;

/**
 * Moving a branch of the tree, `null` for the root.
 *
 * Two refusals guard it. A folder cannot go into itself or into anything
 * below it, which would detach the whole branch from the root and hide every
 * file in it; and the destination must not already hold a folder of that
 * name, for the same reason creating one there would be refused.
 */
final readonly class MoveFolder
{
    /**
     * @throws ForbiddenMediaActionException
     * @throws InvalidFolderMoveException
     * @throws DuplicateFolderNameException
     */
    public function handle(CmsMediaFolder $folder, ?CmsMediaFolder $target, Actor $actor): CmsMediaFolder
    {
        if (! $actor->isAdmin()) {
            throw ForbiddenMediaActionException::forFolders();
        }

        if ($target instanceof CmsMediaFolder && ($target->id === $folder->id || $target->isDescendantOf($folder))) {
            throw InvalidFolderMoveException::forFolder($folder);
        }

        FolderSiblings::assertNameIsFree($target, $folder->name, $folder->id);

        $folder->parent_id = $target?->id;

        return FolderSiblings::guardUnique($folder->name, static function () use ($folder): CmsMediaFolder {
            $folder->save();

            return $folder;
        });
    }
}
