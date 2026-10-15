<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Actions;

use Ewk\Cms\Media\Exceptions\ForbiddenMediaActionException;
use Ewk\Cms\Media\Exceptions\InvalidMediaNameException;
use Ewk\Cms\Media\Models\CmsMedia;
use Ewk\Cms\Media\Support\Actor;
use Ewk\Cms\Media\Support\MediaName;

/**
 * The name the panel shows. It has nothing to do with the file on the disk:
 * renaming changes no address, and the name the client sent stays in
 * `original_name` for whoever has to find the file they uploaded.
 */
final readonly class RenameMedia
{
    /**
     * @throws ForbiddenMediaActionException
     * @throws InvalidMediaNameException
     */
    public function handle(CmsMedia $media, string $name, Actor $actor): CmsMedia
    {
        if (! $actor->canManage($media)) {
            throw ForbiddenMediaActionException::forMedia($media);
        }

        $media->name = MediaName::normalize($name);
        $media->save();

        return $media;
    }
}
