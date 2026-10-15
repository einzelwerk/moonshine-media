<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Tests\MoonShine\Fixtures\MoonShine;

use Ewk\Cms\Media\MoonShine\Fields\MediaPicker;
use Ewk\Cms\Media\MoonShine\Library\PickerModal;
use MoonShine\Laravel\Pages\Crud\FormPage;
use MoonShine\UI\Components\FlexibleRender;
use MoonShine\UI\Fields\Json;
use MoonShine\UI\Fields\Text;

/**
 * A form whose only pictures live inside the rows of a slider. No field of a
 * row draws the dialog, so the screen draws it itself, last, the way
 * {@see PickerModal} asks a host to.
 *
 * @extends FormPage<SlideshowResource>
 */
final class SlideshowFormPage extends FormPage
{
    protected function fields(): iterable
    {
        return [
            Json::make('Слайды', 'slides')->fields([
                Text::make('Заголовок', 'title'),
                MediaPicker::make('Картинка', 'image'),
            ]),
            FlexibleRender::make(static fn (): string => PickerModal::once()),
        ];
    }
}
