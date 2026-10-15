<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Tests\MoonShine\Fixtures;

use Ewk\Cms\Media\Models\CmsMediaUsage;
use Ewk\Cms\Media\Usages\UsageLabelResolver;
use Ewk\Cms\Media\Usages\UsageOwnerLabel;

/**
 * Names a {@see GalleryOwner} in «where is this file used», the way a package
 * that owns records names its own: a title, a kind key and the alias and the
 * key of the record, and no address.
 */
final readonly class GalleryOwnerUsageLabelResolver implements UsageLabelResolver
{
    public function supports(string $usableType): bool
    {
        return $usableType === GalleryOwner::MORPH_ALIAS;
    }

    public function resolve(CmsMediaUsage $usage): ?UsageOwnerLabel
    {
        $owner = $usage->usable;

        if (! $owner instanceof GalleryOwner) {
            return null;
        }

        return new UsageOwnerLabel(
            title: (string) ($owner->name['ru'] ?? $owner->title ?? '#'.$owner->id),
            type: 'venue',
            ownerType: GalleryOwner::MORPH_ALIAS,
            ownerId: $owner->id,
        );
    }
}
