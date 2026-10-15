<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Actions;

use Ewk\Cms\Media\Exceptions\DuplicateFolderNameException;
use Ewk\Cms\Media\Exceptions\ForbiddenMediaActionException;
use Ewk\Cms\Media\Exceptions\InvalidMediaNameException;
use Ewk\Cms\Media\Models\CmsMediaFolder;
use Ewk\Cms\Media\Support\Actor;
use Ewk\Cms\Media\Support\FolderSiblings;
use Ewk\Cms\Media\Support\MediaName;

/**
 * Renaming a folder changes no address on any disk: a path is built from the
 * uuid of a file.
 */
final readonly class RenameFolder
{
    /**
     * @throws ForbiddenMediaActionException
     * @throws InvalidMediaNameException
     * @throws DuplicateFolderNameException
     */
    public function handle(CmsMediaFolder $folder, string $name, Actor $actor): CmsMediaFolder
    {
        if (! $actor->isAdmin()) {
            throw ForbiddenMediaActionException::forFolders();
        }

        $name = MediaName::normalize($name);
        FolderSiblings::assertNameIsFree($folder->parent, $name, $folder->id);

        $folder->name = $name;

        return FolderSiblings::guardUnique($name, static function () use ($folder): CmsMediaFolder {
            $folder->save();

            return $folder;
        });
    }
}
