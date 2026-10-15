<?php

declare(strict_types=1);

use Ewk\Cms\Media\Enums\FileKind;
use Ewk\Cms\Media\Enums\MediaVariantState;
use Ewk\Cms\Media\Models\CmsMedia;
use Ewk\Cms\Media\Models\CmsMediaUsage;
use Ewk\Cms\Media\Models\CmsMediaVariant;
use Ewk\Cms\Media\Tests\MoonShine\Fixtures\GalleryOwner;
use Ewk\Cms\Media\Tests\MoonShine\Fixtures\MoonShine\GalleryOwnerResource;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->actingAsAdmin();
});

/**
 * @param  array<string, mixed>  $attributes
 */
function attachedFile(string $name, array $attributes = []): CmsMedia
{
    return CmsMedia::query()->create($attributes + [
        'disk' => 'public',
        'path' => 'cms/'.Illuminate\Support\Str::slug($name).'.jpg',
        'original_name' => Illuminate\Support\Str::slug($name).'.jpg',
        'name' => $name,
        'mime' => 'image/jpeg',
        'size' => 1468006,
    ]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function galleryOwner(array $attributes = []): GalleryOwner
{
    return GalleryOwner::query()->create($attributes + ['title' => 'Клуб', 'name' => ['ru' => 'Клуб']]);
}

function galleryResource(): GalleryOwnerResource
{
    return app(GalleryOwnerResource::class);
}

/**
 * @param  array<string, mixed>  $present
 * @param  array<string, mixed>  $attachments
 */
function saveGallery(GalleryOwner $owner, array $present, array $attachments): Illuminate\Testing\TestResponse
{
    return submitForm(galleryResource()->getRoute('crud.update', $owner->getKey()), [
        'title' => 'Клуб',
        'media_attachments_present' => $present,
        'media_attachments' => $attachments,
    ], 'PUT');
}

/**
 * The uuids of one role, in the order the rows hold them.
 *
 * @return list<string>
 */
function attachedUuids(GalleryOwner $owner, string $role, ?string $locale = null): array
{
    return $owner->fresh()?->attachedMedia($role, $locale)
        ->map(static fn (CmsMedia $media): string => $media->uuid)
        ->all() ?? [];
}

/* ------------------------------------------------------------- rendering */

it('draws the file a record owns, with its name and its size', function (): void {
    $owner = galleryOwner();
    $logo = attachedFile('Логотип клуба');

    app(Ewk\Cms\Media\Services\MediaAttachments::class)->replace($owner, 'logo', null, [$logo]);

    $html = $this->get(galleryResource()->getFormPageUrl($owner->getKey()))->assertOk()->getContent();

    expect($html)->toContain('Логотип клуба')
        // A size is read in words and never in bytes.
        ->and($html)->toContain('1.4 MB')
        ->and($html)->not->toContain('1468006')
        ->and(plainUrls($html))->toContain('cms/logotip-kluba.jpg')
        // The ordered list the form submits, written by the server so that a
        // browser whose script never ran submits the state unchanged.
        ->and($html)->toContain('name="media_attachments[logo][_][]"')
        ->and($html)->toContain('value="'.$logo->uuid.'"')
        // And the marker that says this role was on the form at all.
        ->and($html)->toContain('name="media_attachments_present[logo][_]"')
        // The uuid counts as something the editor filled, so a language
        // switch of the form does not badge a box holding a picture as an
        // empty translation.
        ->and($html)->toContain('data-cms-counts')
        // Every label the box needs is translated; a missing key would leave
        // its own name in the markup.
        ->and($html)->not->toContain('attachments.remove_named');
});

it('draws a gallery in the order of its positions', function (): void {
    $owner = galleryOwner();
    $first = attachedFile('Первая');
    $second = attachedFile('Вторая');
    $third = attachedFile('Третья');

    app(Ewk\Cms\Media\Services\MediaAttachments::class)
        ->replace($owner, 'gallery', null, [$third, $first, $second]);

    $html = $this->get(galleryResource()->getFormPageUrl($owner->getKey()))->assertOk()->getContent();

    $positions = array_map(
        static fn (CmsMedia $media): int => (int) mb_strpos($html, 'value="'.$media->uuid.'"'),
        [$third, $first, $second],
    );

    expect($positions[0])->toBeGreaterThan(0)
        ->and($positions[1])->toBeGreaterThan($positions[0])
        ->and($positions[2])->toBeGreaterThan($positions[1]);
});

it('draws an empty field without a single hidden uuid', function (): void {
    $owner = galleryOwner();

    $html = $this->get(galleryResource()->getFormPageUrl($owner->getKey()))->assertOk()->getContent();

    expect($html)->toContain((string) __('moonshine-media::media.attachments.empty'))
        ->and($html)->toContain((string) __('moonshine-media::media.attachments.empty_multiple'))
        ->and($html)->toContain('name="media_attachments_present[gallery][_]"')
        ->and($html)->not->toContain('name="media_attachments[gallery][_][]"');
});

it('marks a field of one language so that the switch of the form drives it', function (): void {
    $owner = galleryOwner();

    app(Ewk\Cms\Media\Services\MediaAttachments::class)
        ->replace($owner, 'cover', 'ru', [attachedFile('Обложка')]);

    $html = $this->get(galleryResource()->getFormPageUrl($owner->getKey()))->assertOk()->getContent();

    // One field per language, each marked the way a translatable field marks
    // itself, so the one switch of the form shows one at a time.
    expect($html)->toContain('data-cms-locale="ru"')
        ->and($html)->toContain('data-cms-locale="en"')
        ->and($html)->toContain('name="media_attachments[cover][ru][]"')
        ->and($html)->toContain('name="media_attachments_present[cover][en]"')
        // And a role without a language keeps the place of its own.
        ->and($html)->toContain('name="media_attachments_present[logo][_]"');
});

it('prefers the thumbnail of the library over the original', function (): void {
    $owner = galleryOwner();
    $logo = attachedFile('Логотип клуба');

    CmsMediaVariant::query()->create([
        'cms_media_id' => $logo->getKey(),
        'preset' => 'thumb',
        'disk' => 'public',
        'path' => 'cms/logo-thumb.webp',
        'format' => 'webp',
        'width' => 400,
        'height' => 400,
        'size' => 12000,
        'state' => MediaVariantState::READY,
    ]);

    app(Ewk\Cms\Media\Services\MediaAttachments::class)->replace($owner, 'logo', null, [$logo]);

    expect(plainUrls($this->get(galleryResource()->getFormPageUrl($owner->getKey()))->assertOk()->getContent()))
        ->toContain('cms/logo-thumb.webp');
});

it('draws the dialog of the library once for a form full of fields', function (): void {
    $owner = galleryOwner();

    $html = $this->get(galleryResource()->getFormPageUrl($owner->getKey()))->assertOk()->getContent();

    expect(pickerDialogCount($html))->toBe(1)
        ->and(plainUrls($html))->toContain('/cms/media/picker');
});

/* ---------------------------------------------------------------- saving */

it('writes a role in the order the form submitted it and touches no other role', function (): void {
    $owner = galleryOwner();
    $first = attachedFile('Первая');
    $second = attachedFile('Вторая');
    $third = attachedFile('Третья');
    $logo = attachedFile('Логотип');

    app(Ewk\Cms\Media\Services\MediaAttachments::class)->replace($owner, 'gallery', null, [$first, $second, $third]);
    app(Ewk\Cms\Media\Services\MediaAttachments::class)->replace($owner, 'logo', null, [$logo]);

    saveGallery($owner, ['gallery' => ['_' => '1'], 'cover' => ['ru' => '1']], [
        'gallery' => ['_' => [$third->uuid, $first->uuid, $second->uuid]],
        'cover' => ['ru' => [$first->uuid]],
    ])->assertSuccessful();

    expect(attachedUuids($owner, 'gallery'))->toBe([$third->uuid, $first->uuid, $second->uuid])
        ->and(attachedUuids($owner, 'logo'))->toBe([$logo->uuid])
        ->and(attachedUuids($owner, 'cover', 'ru'))->toBe([$first->uuid])
        // The language of a role is its own place: the Russian cover says
        // nothing about the English one.
        ->and(attachedUuids($owner, 'cover', 'en'))->toBe([]);
});

it('empties a role the editor cleared and leaves a role no form carried', function (): void {
    $owner = galleryOwner();
    $picture = attachedFile('Первая');
    $logo = attachedFile('Логотип');

    $attachments = app(Ewk\Cms\Media\Services\MediaAttachments::class);
    $attachments->replace($owner, 'gallery', null, [$picture]);
    $attachments->replace($owner, 'logo', null, [$logo]);
    $attachments->replace($owner, 'cover', 'en', [$picture]);

    // The gallery was on the form and is empty; «cover.en» was not on it at
    // all, so nothing about it is decided here.
    saveGallery($owner, ['gallery' => ['_' => '1'], 'logo' => ['_' => '1'], 'cover' => ['ru' => '1']], ['logo' => ['_' => [$logo->uuid]], 'cover' => ['ru' => [$picture->uuid]]])->assertSuccessful();

    expect(attachedUuids($owner, 'gallery'))->toBe([])
        ->and(attachedUuids($owner, 'logo'))->toBe([$logo->uuid])
        ->and(attachedUuids($owner, 'cover', 'en'))->toBe([$picture->uuid]);
});

it('drops a uuid the library does not know instead of failing the save', function (): void {
    $owner = galleryOwner();
    $picture = attachedFile('Первая');

    saveGallery($owner, ['gallery' => ['_' => '1'], 'cover' => ['ru' => '1']], [
        'gallery' => ['_' => ['9f3c1a2b-4e55-4c1e-9a3f-0b71d2c35e10', $picture->uuid]],
        'cover' => ['ru' => [$picture->uuid]],
    ])->assertSuccessful();

    expect(attachedUuids($owner, 'gallery'))->toBe([$picture->uuid])
        ->and(CmsMediaUsage::query()->where('field', 'gallery')->count())->toBe(1);
});

it('drops a file of a kind the field does not take', function (): void {
    $owner = galleryOwner();
    $picture = attachedFile('Первая');
    // A document that never went through this field: the dialog shows it no
    // such file and the upload refuses one with 415, so a request carrying it
    // was written by hand.
    $document = attachedFile('Отчёт', ['kind' => FileKind::DOCUMENT, 'mime' => 'application/pdf']);

    saveGallery($owner, ['gallery' => ['_' => '1'], 'cover' => ['ru' => '1']], [
        'gallery' => ['_' => [$document->uuid, $picture->uuid]],
        'cover' => ['ru' => [$picture->uuid]],
    ])->assertSuccessful();

    expect(attachedUuids($owner, 'gallery'))->toBe([$picture->uuid]);
});

it('writes no role the form does not carry', function (): void {
    $owner = galleryOwner();
    $picture = attachedFile('Первая');

    saveGallery($owner, [
        'cover' => ['ru' => '1', 'de' => '1'],
        // Neither of these is a field of this form.
        'plan_svg' => ['_' => '1'],
    ], [
        'cover' => ['ru' => [$picture->uuid], 'de' => [$picture->uuid]],
        'plan_svg' => ['_' => [$picture->uuid]],
    ])->assertSuccessful();

    expect(attachedUuids($owner, 'cover', 'ru'))->toBe([$picture->uuid])
        ->and(attachedUuids($owner, 'plan_svg'))->toBe([])
        ->and(attachedUuids($owner, 'cover', 'de'))->toBe([]);
});

it('writes the attachments of a record that is being created', function (): void {
    $picture = attachedFile('Первая');

    submitForm(galleryResource()->getRoute('crud.store'), [
        'title' => 'Новый клуб',
        'media_attachments_present' => ['logo' => ['_' => '1'], 'cover' => ['ru' => '1']],
        'media_attachments' => [
            'logo' => ['_' => [$picture->uuid]],
            'cover' => ['ru' => [$picture->uuid]],
        ],
    ])->assertSuccessful();

    $owner = GalleryOwner::query()->latest('id')->sole();

    expect(attachedUuids($owner, 'logo'))->toBe([$picture->uuid]);
});

it('refuses a gallery over its limit and a required cover that is empty', function (): void {
    $owner = galleryOwner();
    $files = [attachedFile('Одна'), attachedFile('Две'), attachedFile('Три'), attachedFile('Четыре')];

    saveGallery($owner, ['gallery' => ['_' => '1'], 'cover' => ['ru' => '1']], [
        'gallery' => ['_' => array_map(static fn (CmsMedia $media): string => $media->uuid, $files)],
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['media_attachments.gallery._', 'media_attachments.cover.ru']);

    expect(attachedUuids($owner, 'gallery'))->toBe([]);
});

it('refuses anything but a uuid in a group', function (): void {
    $owner = galleryOwner();

    saveGallery($owner, ['gallery' => ['_' => '1'], 'cover' => ['ru' => '1']], ['gallery' => ['_' => ['17']], 'cover' => ['ru' => ['17']]])->assertStatus(422)->assertJsonValidationErrors(['media_attachments.gallery._.0']);
});

/* --------------------------------------------------------------- listing */

it('draws the picture of a record beside its name in the listing', function (): void {
    $owner = galleryOwner(['name' => ['ru' => 'Клуб Прима']]);
    $logo = attachedFile('Логотип клуба');

    app(Ewk\Cms\Media\Services\MediaAttachments::class)->replace($owner, 'logo', null, [$logo]);

    $html = indexRows(galleryResource())->assertOk()->getContent();

    expect($html)->toContain('Клуб Прима')
        ->and($html)->toContain('cms-attachment-preview__thumb')
        ->and(plainUrls($html))->toContain('cms/logotip-kluba.jpg');
});

it('draws a record without a picture as the empty mark and the name alone', function (): void {
    galleryOwner(['name' => ['ru' => 'Клуб без логотипа']]);

    $html = indexRows(galleryResource())->assertOk()->getContent();

    expect($html)->toContain('Клуб без логотипа')
        ->and($html)->toContain('cms-attachment-preview__thumb--empty');
});

it('draws a listing of many records without a query per record', function (): void {
    $counted = static function (int $records): int {
        GalleryOwner::query()->delete();

        foreach (range(1, $records) as $number) {
            $owner = galleryOwner(['title' => 'Клуб '.$number, 'name' => ['ru' => 'Клуб '.$number]]);

            app(Ewk\Cms\Media\Services\MediaAttachments::class)
                ->replace($owner, 'logo', null, [attachedFile('Логотип '.$number)]);
        }

        $queries = 0;
        DB::listen(static function () use (&$queries): void {
            $queries++;
        });

        indexRows(galleryResource())->assertOk();
        DB::flushQueryLog();

        return $queries;
    };

    // The scope of the media package loads the rows, their files and the
    // variants of those files in one go, see GalleryOwnerResource: five
    // records cost what one costs. A listing that forgets it pays a query per
    // row instead, which is the only way back to N+1 here.
    expect($counted(5))->toBe($counted(1));
});
