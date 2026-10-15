<?php

declare(strict_types=1);

use Ewk\Cms\Media\Enums\FileKind;
use Ewk\Cms\Media\Enums\MediaVariantState;
use Ewk\Cms\Media\Models\CmsMedia;
use Ewk\Cms\Media\Models\CmsMediaVariant;
use Ewk\Cms\Media\MoonShine\Fields\MediaPicker;
use Ewk\Cms\Media\Tests\MoonShine\Fixtures\MoonShine\PosterResource;
use Ewk\Cms\Media\Tests\MoonShine\Fixtures\MoonShine\SlideshowResource;
use Ewk\Cms\Media\Tests\MoonShine\Fixtures\Poster;

beforeEach(function (): void {
    $this->actingAsAdmin();
});

/**
 * @param  array<string, mixed>  $attributes
 */
function libraryFile(array $attributes = []): CmsMedia
{
    return CmsMedia::query()->create($attributes + [
        'disk' => 'public',
        'path' => 'cms/poster.jpg',
        'original_name' => 'poster.jpg',
        'name' => 'Афиша фестиваля',
        'mime' => 'image/jpeg',
        'size' => 1468006,
    ]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function poster(array $attributes = []): Poster
{
    return Poster::query()->create($attributes + ['title' => 'Фестиваль']);
}

/**
 * The form of one record, as the panel draws it.
 */
function posterForm(Poster $poster, string $resource = PosterResource::class): string
{
    /** @var PosterResource|SlideshowResource $instance */
    $instance = app($resource);

    return (string) test()->get($instance->getFormPageUrl($poster->getKey()))->assertOk()->getContent();
}

it('draws the picture that is stored, with its name and its size', function (): void {
    $media = libraryFile();

    $html = posterForm(poster(['image' => ['id' => $media->uuid]]));

    expect(plainUrls($html))->toContain('cms/poster.jpg')
        ->and($html)->toContain('Афиша фестиваля')
        // A size is read in words and never in bytes, in the units of the
        // panel, exactly as the library prints them — «1,4 МБ» to an editor
        // working in Russian.
        ->and($html)->toContain('1.4 MB')
        ->and($html)->not->toContain('1468006')
        // The zone of the panel, with the reference inside it.
        ->and($html)->toContain('dropzone cms-media__zone')
        ->and($html)->toContain('name="image[id]"')
        // Nothing of this field is submitted as a file: the upload happens
        // when a picture is chosen.
        ->and($html)->not->toContain('name="image[file]"');
});

it('offers the library and the desktop, and the description of this one place', function (): void {
    $media = libraryFile();

    $html = posterForm(poster(['image' => ['id' => $media->uuid, 'metadata' => ['alt' => ['ru' => 'Сцена']]]]));

    // The two ways of putting a picture there are named by where it comes
    // from and not by what they do: «Заменить» beside «Загрузить» read as one
    // action written twice, because both of them replace the picture.
    expect($html)->toContain((string) __('moonshine-media::media.choose'))
        ->and($html)->toContain((string) __('moonshine-media::media.upload'))
        ->and($html)->toContain((string) __('moonshine-media::media.clear'))
        ->and($html)->not->toContain('Заменить')
        ->and($html)->toContain((string) __('moonshine-media::media.alt'))
        // One input per language of the catalog, each carrying the language
        // it belongs to and not a badge of the form's own.
        ->and($html)->toContain('name="image[metadata][alt][ru]"')
        ->and($html)->toContain('name="image[metadata][alt][en]"')
        ->and($html)->toContain('data-cms-alt-locale="en"')
        ->and($html)->toContain('value="Сцена"');
});

it('draws the dialog of the library once for a form full of pictures', function (): void {
    $html = posterForm(poster(['slides' => [['title' => 'Первый'], ['title' => 'Второй']]]));

    expect(pickerDialogCount($html))->toBe(1)
        ->and(plainUrls($html))->toContain('/cms/media/picker');
});

it('leaves the dialog to the screen when the only pictures live in a repeater', function (): void {
    // A repeater row is rendered once as a hidden template and cloned per row,
    // so a field inside one never draws the dialog: the screen draws it
    // instead, see \Ewk\Cms\Media\MoonShine\Library\PickerModal.
    $html = posterForm(
        poster(['slides' => [['title' => 'Первый'], ['title' => 'Второй']]]),
        SlideshowResource::class,
    );

    expect(pickerDialogCount($html))->toBe(1);
});

it('names the inputs of a row of a repeater so that the panel can renumber them', function (): void {
    $html = posterForm(poster(['slides' => [['title' => 'Первый']]]));

    // Every input of the field carries the marks the repeater renames it by,
    // see {@see \Ewk\Cms\Media\MoonShine\Fields\CompositeValueField}:
    // without them the second slide would write over the first.
    expect($html)->toContain('name="slides[${index0}][image][id]"')
        ->and($html)->toContain('data-name="slides[${index0}][image][id]"')
        ->and($html)->toContain('name="slides[${index0}][image][metadata][alt][ru]"')
        ->and($html)->toContain('data-name="slides[${index0}][image][metadata][alt][ru]"');
});

it('prefers the thumbnail of the library over the original', function (): void {
    $media = libraryFile();

    CmsMediaVariant::query()->create([
        'cms_media_id' => $media->getKey(),
        'preset' => 'thumb',
        'disk' => 'public',
        'path' => 'cms/poster-thumb.webp',
        'format' => 'webp',
        'width' => 400,
        'height' => 400,
        'size' => 12000,
        'state' => MediaVariantState::READY,
    ]);

    expect(plainUrls(posterForm(poster(['image' => ['id' => $media->uuid]]))))
        ->toContain('cms/poster-thumb.webp');
});

it('draws the frame of a video and never the video itself as a picture', function (): void {
    $media = libraryFile([
        'path' => 'cms/loop.mp4',
        'original_name' => 'loop.mp4',
        'name' => 'Фон',
        'kind' => FileKind::VIDEO,
        'mime' => 'video/mp4',
    ]);

    $poster = poster(['image' => ['id' => $media->uuid]]);

    // No frame cut: the glyph of the field, not an <img> of an mp4.
    expect(plainUrls(posterForm($poster)))->not->toContain('cms/loop.mp4');

    CmsMediaVariant::query()->create([
        'cms_media_id' => $media->getKey(),
        'preset' => 'thumb',
        'disk' => 'public',
        'path' => 'cms/loop-thumb.webp',
        'format' => 'webp',
        'width' => 400,
        'height' => 225,
        'size' => 9000,
        'state' => MediaVariantState::READY,
    ]);

    expect(plainUrls(posterForm($poster)))->toContain('cms/loop-thumb.webp');
});

it('says a reference whose file is gone needs a new one', function (): void {
    $html = posterForm(poster(['image' => ['id' => '9f3c1a2b-4e55-4c1e-9a3f-0b71d2c35e10']]));

    expect($html)->toContain('missing: true')
        ->and($html)->toContain((string) __('moonshine-media::media.missing'));
});

it('opens an empty field on the zone and not on a bare file input', function (): void {
    $html = posterForm(poster());

    expect($html)->toContain((string) __('moonshine-media::media.empty'))
        ->and($html)->toContain((string) __('moonshine-media::media.choose'))
        ->and($html)->toContain('id: null');
});

it('takes the kinds the field declares into the dialog and into the upload', function (): void {
    $field = MediaPicker::make('Файл', 'file');

    // The default of a field that says nothing: a picture or an icon.
    expect($field->getKinds())->toBe(['image', 'vector'])
        ->and($field->kinds(['document', 'nonsense'])->getKinds())->toBe(['document'])
        // A narrowing that names nothing the library knows narrows to nothing
        // and therefore falls back instead of taking everything.
        ->and($field->kinds(['nonsense'])->getKinds())->toBe(['image', 'vector']);
});

it('offers the file dialog only the extensions of the kinds it takes', function (): void {
    $html = posterForm(poster());

    // A field for pictures and icons: a walk through a folder of videos is
    // spared, and the server still decides what it keeps.
    expect($html)->toContain('accept=".jpg,')
        ->and($html)->toContain('.svg"')
        ->and($html)->not->toContain('.mp4');
});

it('offers no description where the writer of the form stores none', function (): void {
    $html = posterForm(poster());

    // A card picture whose row has a column for the picture and none for its
    // caption: an input dropped on the way to the database is worse than no
    // input at all.
    expect($html)->toContain('name="og_image[id]"')
        ->and($html)->not->toContain('name="og_image[metadata][alt][ru]"')
        ->and($html)->toContain('name="image[metadata][alt][ru]"');
});
