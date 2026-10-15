<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Actions;

use Ewk\Cms\Media\Exceptions\ForbiddenMediaActionException;
use Ewk\Cms\Media\Models\CmsMedia;
use Ewk\Cms\Media\Models\CmsMediaFolder;
use Ewk\Cms\Media\Support\Actor;

/**
 * Moving files between folders.
 *
 * Nothing is touched on a disk: folders are virtual and a path is built from
 * the uuid alone, so a move is one column of one row.
 *
 * The tree is shared by everyone who works in the panel, so arranging it is
 * an administrator's job in this version: an owner who only ever sees their
 * own files would otherwise be filing them into folders they cannot see.
 */
final readonly class MoveMedia
{
    /**
     * @param  iterable<array-key, CmsMedia>  $media
     *
     * @throws ForbiddenMediaActionException
     */
    public function handle(iterable $media, ?CmsMediaFolder $folder, Actor $actor): void
    {
        if (! $actor->isAdmin()) {
            throw ForbiddenMediaActionException::forFolders();
        }

        foreach ($media as $record) {
            if (! $actor->canManage($record)) {
                throw ForbiddenMediaActionException::forMedia($record);
            }

            $record->folder_id = $folder?->id;
            $record->save();
        }
    }
}
