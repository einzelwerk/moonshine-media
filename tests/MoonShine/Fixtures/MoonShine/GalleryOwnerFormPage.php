<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Tests\MoonShine\Fixtures\MoonShine;

use Ewk\Cms\Media\MoonShine\Fields\MediaAttachmentsField;
use Ewk\Cms\Media\Tests\MoonShine\Fixtures\GalleryOwner;
use MoonShine\Contracts\Core\TypeCasts\DataWrapperContract;
use MoonShine\Laravel\Pages\Crud\FormPage;
use MoonShine\UI\Components\Layout\Box;
use MoonShine\UI\Fields\Text;

/**
 * The form of an owner of pictures: a logo with no language, a gallery of at
 * most three, and a cover per language inside a box of its own, the way the
 * forms of the tours are built.
 *
 * @extends FormPage<GalleryOwnerResource>
 */
final class GalleryOwnerFormPage extends FormPage
{
    protected function fields(): iterable
    {
        return [
            Box::make([
                Text::make('Title', 'title'),
                MediaAttachmentsField::make('Логотип', 'logo'),
                MediaAttachmentsField::make('Галерея', 'gallery')->multiple()->max(3),
            ]),
            Box::make('Обложка RU', [
                MediaAttachmentsField::make('Обложка', 'cover')->locale('ru'),
            ]),
            Box::make('Обложка EN', [
                MediaAttachmentsField::make('Обложка', 'cover')->locale('en'),
            ]),
        ];
    }

    /**
     * @param  DataWrapperContract<GalleryOwner>  $item
     * @return array<string, mixed>
     */
    protected function rules(DataWrapperContract $item): array
    {
        $resource = $this->getResource();

        return [
            ...$resource->mediaAttachmentRules('gallery', max: 3),
            ...$resource->mediaAttachmentRules('cover', 'ru', required: true),
        ];
    }
}
