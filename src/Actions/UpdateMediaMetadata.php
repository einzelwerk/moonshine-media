<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Actions;

use Ewk\Cms\Media\Exceptions\ForbiddenMediaActionException;
use Ewk\Cms\Media\Exceptions\InvalidMediaLocaleException;
use Ewk\Cms\Media\Models\CmsMedia;
use Ewk\Cms\Media\Support\Actor;
use Ewk\Cms\Media\Support\MediaMetadata;

/**
 * The default `alt`, `caption` and `title` of a record: a use of the file may
 * override them, and an empty override inherits what is written here.
 */
final readonly class UpdateMediaMetadata
{
    /**
     * @throws ForbiddenMediaActionException
     * @throws InvalidMediaLocaleException
     */
    public function handle(CmsMedia $media, MediaMetadata $metadata, Actor $actor): CmsMedia
    {
        if (! $actor->canManage($media)) {
            throw ForbiddenMediaActionException::forMedia($media);
        }

        $merged = $metadata->mergeInto($media->metadata);

        $media->metadata = $merged === [] ? null : $merged;
        $media->save();

        return $media;
    }
}
