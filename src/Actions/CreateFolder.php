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
 * A new folder of the library. The tree is shared, so it is arranged by an
 * administrator; nothing is created on a disk.
 */
final readonly class CreateFolder
{
    /**
     * @throws ForbiddenMediaActionException
     * @throws InvalidMediaNameException
     * @throws DuplicateFolderNameException
     */
    public function handle(?CmsMediaFolder $parent, string $name, Actor $actor): CmsMediaFolder
    {
        if (! $actor->isAdmin()) {
            throw ForbiddenMediaActionException::forFolders();
        }

        $name = MediaName::normalize($name);
        FolderSiblings::assertNameIsFree($parent, $name);

        // The pre-check above is not the last word: the index folds accents
        // as well as case, and two editors can pass the check at the same
        // moment, so the write answers with the same sentence either way.
        return FolderSiblings::guardUnique($name, static fn (): CmsMediaFolder => CmsMediaFolder::query()->create([
            'parent_id' => $parent?->id,
            'name' => $name,
        ]));
    }
}
