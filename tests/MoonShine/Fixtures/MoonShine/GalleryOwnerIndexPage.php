<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Tests\MoonShine\Fixtures\MoonShine;

use Ewk\Cms\Media\MoonShine\Fields\AttachmentPreview;
use MoonShine\Laravel\Pages\Crud\IndexPage;
use MoonShine\UI\Fields\ID;

/**
 * @extends IndexPage<GalleryOwnerResource>
 */
final class GalleryOwnerIndexPage extends IndexPage
{
    protected function fields(): iterable
    {
        return [
            ID::make(),
            AttachmentPreview::make('Площадка', 'logo')->withTitle('name'),
        ];
    }
}
