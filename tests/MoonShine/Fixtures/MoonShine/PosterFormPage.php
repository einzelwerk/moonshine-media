<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Tests\MoonShine\Fixtures\MoonShine;

use Ewk\Cms\Media\MoonShine\Fields\MediaPicker;
use MoonShine\Laravel\Pages\Crud\FormPage;
use MoonShine\UI\Fields\Json;
use MoonShine\UI\Fields\Text;

/**
 * A form full of pictures: one with its description, one whose writer keeps
 * the reference alone, and one per row of a slider.
 *
 * @extends FormPage<PosterResource>
 */
final class PosterFormPage extends FormPage
{
    protected function fields(): iterable
    {
        return [
            Text::make('Title', 'title'),
            MediaPicker::make('Картинка', 'image'),
            MediaPicker::make('Картинка для соцсетей', 'og_image')->withoutDescription(),
            Json::make('Слайды', 'slides')->fields([
                Text::make('Заголовок', 'title'),
                MediaPicker::make('Картинка', 'image'),
            ]),
        ];
    }
}
