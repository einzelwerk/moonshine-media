<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Tests\MoonShine\Fixtures\MoonShine;

use MoonShine\Laravel\Pages\Crud\IndexPage;
use MoonShine\UI\Fields\ID;
use MoonShine\UI\Fields\Text;

/**
 * @extends IndexPage<PosterResource>
 */
final class PosterIndexPage extends IndexPage
{
    protected function fields(): iterable
    {
        return [
            ID::make(),
            Text::make('Title', 'title'),
        ];
    }
}
