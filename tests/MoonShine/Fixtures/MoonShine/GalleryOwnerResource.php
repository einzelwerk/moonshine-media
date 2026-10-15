<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Tests\MoonShine\Fixtures\MoonShine;

use Ewk\Cms\Media\MoonShine\Concerns\InteractsWithMediaAttachments;
use Ewk\Cms\Media\Tests\MoonShine\Fixtures\GalleryOwner;
use Illuminate\Contracts\Database\Eloquent\Builder;
use MoonShine\Laravel\Resources\ModelResource;

/**
 * The resource an installation would write for a venue: the record carries
 * the trait of the library, the resource carries the concern, and the rows of
 * `cms_media_usages` are written once the record has a key.
 *
 * @extends ModelResource<GalleryOwner, GalleryOwnerIndexPage, GalleryOwnerFormPage, GalleryOwnerFormPage>
 */
final class GalleryOwnerResource extends ModelResource
{
    use InteractsWithMediaAttachments;

    protected string $model = GalleryOwner::class;

    protected string $column = 'title';

    protected string $title = 'Venues';

    /**
     * The listing draws the logo of every row, so it loads them with the
     * scope of the media package: without it every row asks for its own,
     * which is the one way back to a query per row.
     *
     * @param  Builder<GalleryOwner>  $builder
     * @return Builder<GalleryOwner>
     */
    protected function modifyQueryBuilder(Builder $builder): Builder
    {
        return $builder->withAttachedMedia(['logo']);
    }

    protected function pages(): array
    {
        return [
            GalleryOwnerIndexPage::class,
            GalleryOwnerFormPage::class,
        ];
    }
}
