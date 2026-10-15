<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Tests\MoonShine\Fixtures\MoonShine;

use Ewk\Cms\Media\Tests\MoonShine\Fixtures\Poster;
use MoonShine\Laravel\Resources\ModelResource;

/**
 * @extends ModelResource<Poster, PosterIndexPage, SlideshowFormPage, SlideshowFormPage>
 */
final class SlideshowResource extends ModelResource
{
    protected string $model = Poster::class;

    protected string $column = 'title';

    protected string $title = 'Slideshows';

    protected function pages(): array
    {
        return [
            PosterIndexPage::class,
            SlideshowFormPage::class,
        ];
    }
}
