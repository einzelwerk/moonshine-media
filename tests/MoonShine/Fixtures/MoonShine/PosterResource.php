<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Tests\MoonShine\Fixtures\MoonShine;

use Ewk\Cms\Media\Tests\MoonShine\Fixtures\Poster;
use MoonShine\Laravel\Resources\ModelResource;

/**
 * @extends ModelResource<Poster, PosterIndexPage, PosterFormPage, PosterFormPage>
 */
final class PosterResource extends ModelResource
{
    protected string $model = Poster::class;

    protected string $column = 'title';

    protected string $title = 'Posters';

    protected function pages(): array
    {
        return [
            PosterIndexPage::class,
            PosterFormPage::class,
        ];
    }
}
