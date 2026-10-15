<?php

declare(strict_types=1);

use Ewk\Cms\Media\Enums\FileKind;
use Ewk\Cms\Media\Models\CmsMedia;
use Ewk\Cms\Media\Models\CmsMediaFolder;
use Ewk\Cms\Media\Models\CmsMediaUsage;
use Ewk\Cms\Media\MoonShine\Library\UsageLinkRegistry;
use Ewk\Cms\Media\MoonShine\Library\UsageLinks;
use Ewk\Cms\Media\Queries\MediaQuery;
use Ewk\Cms\Media\Tests\MoonShine\Fixtures\GalleryOwner;
use Ewk\Cms\Media\Tests\MoonShine\Fixtures\GalleryOwnerUsageLabelResolver;
use Ewk\Cms\Media\Tests\MoonShine\Fixtures\MoonShine\GalleryOwnerResource;
use Ewk\Cms\Media\Usages\UsageLabelRegistry;
use Ewk\Cms\Media\Usages\UsageOwnerLabel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->actingAsAdmin();
    Storage::fake('public');

    // The copy under test is the one an editor of this installation reads.
    // The suite otherwise runs in English, where «2,4 МБ» is «2.4 MB» and
    // half of these assertions would be about the fallback. The panel sets
    // the locale of every one of its requests from its own configuration, so
    // that is where it has to be said.
    config()->set('moonshine.locale', 'ru');
    app()->setLocale('ru');

    // What a package that owns records does in its provider: it names them
    // in «where is this file used» and says which screen of the panel edits
    // them and what their kind is called.
    app(UsageLabelRegistry::class)->register(new GalleryOwnerUsageLabelResolver());
    app(UsageLinkRegistry::class)
        ->resource(GalleryOwner::MORPH_ALIAS, GalleryOwnerResource::class)
        ->caption('venue', static fn (): string => 'Площадка');
});

function libraryUrl(string $name, array $params = []): string
{
    return route('moonshine.cms.media.'.$name, $params);
}

/**
 * A record of the library written straight into the table: a test about the
 * grid has nothing to say about the upload pipeline, which has a suite of its
 * own in the media package.
 *
 * @param  array<string, mixed>  $attributes
 */
function libraryMedia(array $attributes = []): CmsMedia
{
    return CmsMedia::query()->create($attributes + [
        'disk' => 'public',
        'path' => 'ab/'.uniqid('', false).'/v1/original.jpg',
        'original_name' => 'poster.jpg',
        'name' => 'Афиша',
        'mime' => 'image/jpeg',
        'size' => 2_516_582,
        'width' => 1920,
        'height' => 1080,
        'kind' => FileKind::IMAGE,
    ]);
}

function libraryTreeFolder(string $name, ?CmsMediaFolder $parent = null): CmsMediaFolder
{
    return CmsMediaFolder::query()->create(['name' => $name, 'parent_id' => $parent?->id]);
}

/**
 * A record of the installation a file can stand on.
 */
function libraryOwner(string $name): GalleryOwner
{
    return GalleryOwner::query()->create(['title' => $name, 'name' => ['ru' => $name]]);
}

/* ------------------------------------------------------------------ who may */

it('answers nobody outside the panel', function (): void {
    auth('moonshine')->logout();

    $this->get(libraryUrl('items'))->assertRedirect();
    $this->get(libraryUrl('picker'))->assertRedirect();
    $this->post(libraryUrl('delete'), ['uuids' => ['whatever']])->assertRedirect();
});

/* -------------------------------------------------------------------- grid */

it('describes a file the way a tile draws it, and never by a path', function (): void {
    $media = libraryMedia();

    $tile = $this->get(libraryUrl('items'))->assertOk()->json('items.0');

    expect($tile['id'])->toBe($media->uuid)
        ->and($tile['name'])->toBe('Афиша')
        ->and($tile['size_human'])->toBe('2,4 МБ')
        ->and($tile['ext'])->toBe('JPG')
        ->and($tile['kind_label'])->toBe('Фото')
        ->and($tile['has_alt'])->toBeFalse()
        ->and($tile['usages_count'])->toBe(0)
        ->and($tile)->not->toHaveKey('path')
        ->and($tile)->not->toHaveKey('disk')
        ->and($tile)->not->toHaveKey('mime')
        ->and($tile)->not->toHaveKey('checksum');
});

it('narrows the grid by the folder, the kind, the search and the two marks', function (): void {
    $folder = libraryTreeFolder('Афиши');

    libraryMedia(['name' => 'Афиша лета', 'folder_id' => $folder->id]);
    libraryMedia(['name' => 'Логотип', 'kind' => FileKind::VECTOR, 'metadata' => ['alt' => ['ru' => 'Логотип']]]);
    $used = libraryMedia(['name' => 'Обложка']);

    CmsMediaUsage::query()->create([
        'cms_media_id' => $used->id,
        'usable_type' => GalleryOwner::MORPH_ALIAS,
        'usable_id' => libraryOwner('Главная')->getKey(),
        'field' => 'image',
        'path' => 'content.image',
    ]);

    $names = fn (array $query): array => array_column(
        $this->get(libraryUrl('items', $query))->assertOk()->json('items'),
        'name',
    );

    expect($names(['folder' => $folder->id]))->toBe(['Афиша лета'])
        ->and($names(['kind' => 'vector']))->toBe(['Логотип'])
        ->and($names(['q' => 'Облож']))->toBe(['Обложка'])
        // «Без описания» leaves out the one file somebody has described.
        ->and($names(['without_alt' => 1]))->not->toContain('Логотип')
        ->and($names(['unused' => 1]))->not->toContain('Обложка');
});

it('narrows the grid by several kinds at once, the way a field declares them', function (): void {
    libraryMedia(['name' => 'Афиша']);
    libraryMedia(['name' => 'Логотип', 'kind' => FileKind::VECTOR]);
    libraryMedia(['name' => 'Программа', 'kind' => FileKind::DOCUMENT]);

    $names = fn (array $query): array => array_column(
        $this->get(libraryUrl('items', $query))->assertOk()->json('items'),
        'name',
    );

    expect($names(['kinds' => ['image', 'vector']]))->toEqualCanonicalizing(['Афиша', 'Логотип'])
        // One kind in the list is the same request as the chip of the section.
        ->and($names(['kinds' => ['document']]))->toBe(['Программа'])
        // A name that is no kind at all is dropped and not refused: the grid
        // of an editor whose address got mangled shows the library.
        ->and($names(['kinds' => ['image', 'нет такого']]))->toBe(['Афиша'])
        ->and($names(['kinds' => ['нет такого']]))->toHaveCount(3);
});

it('hands out the next page by a cursor and not by a number', function (): void {
    $limit = MediaQuery::DEFAULT_LIMIT;

    foreach (range(1, $limit + 1) as $index) {
        libraryMedia(['name' => 'Файл '.$index]);
    }

    $first = $this->get(libraryUrl('items'))->assertOk()->json();

    expect($first['items'])->toHaveCount($limit)
        ->and($first['next_cursor'])->toBeString()->not->toBe('');

    $second = $this->get(libraryUrl('items', ['cursor' => $first['next_cursor']]))->assertOk()->json();

    $firstNames = array_column($first['items'], 'name');
    $secondNames = array_column($second['items'], 'name');

    expect($second['items'])->toHaveCount(1)
        ->and($second['next_cursor'])->toBeNull()
        // The page after the cursor is the rest and never a repeat.
        ->and(array_intersect($firstNames, $secondNames))->toBe([])
        ->and(count(array_unique([...$firstNames, ...$secondNames])))->toBe($limit + 1)
        // A page number in the address changes nothing.
        ->and(array_column($this->get(libraryUrl('items', ['page' => 2]))->assertOk()->json('items'), 'name'))
        ->toBe($firstNames);
});

it('draws the tree of the folders with the counts beside it', function (): void {
    $folder = libraryTreeFolder('Афиши');
    libraryTreeFolder('Летние', $folder);
    libraryMedia(['folder_id' => $folder->id]);
    libraryMedia();

    $tree = $this->get(libraryUrl('folders'))->assertOk()->json();

    expect($tree['root']['count'])->toBe(2)
        ->and($tree['folders'][0]['name'])->toBe('Афиши')
        ->and($tree['folders'][0]['count'])->toBe(1)
        // A folder with anything inside it is never offered for deletion.
        ->and($tree['folders'][0]['empty'])->toBeFalse()
        ->and($tree['folders'][0]['children'][0]['name'])->toBe('Летние');
});

/* ------------------------------------------------------------------ upload */

it('keeps a picture and answers with the tile of it', function (): void {
    $body = $this->post(libraryUrl('upload'), [
        'file' => UploadedFile::fake()->image('poster.jpg', 600, 400),
    ])->assertOk()->json();

    expect($body['media']['kind'])->toBe('image')
        ->and($body['media']['name'])->toBe('poster')
        ->and(CmsMedia::query()->count())->toBe(1);
});

it('refuses a format the library does not keep', function (): void {
    $this->post(libraryUrl('upload'), [
        'file' => UploadedFile::fake()->create('notes.txt', 4),
    ])->assertStatus(415)->assertJsonStructure(['message']);
});

it('refuses a file larger than its kind allows', function (): void {
    $this->post(libraryUrl('upload'), [
        'file' => UploadedFile::fake()->create('huge.jpg', 21 * 1024),
    ])->assertStatus(413)->assertJsonStructure(['message']);
});

it('offers the file that is already there instead of a second copy of it', function (): void {
    $file = fn (): UploadedFile => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="8" height="8"><rect width="8" height="8"/></svg>');

    $first = $this->post(libraryUrl('upload'), ['file' => $file()])->assertOk()->json('media');
    $second = $this->post(libraryUrl('upload'), ['file' => $file()])->assertOk()->json();

    expect($second)->toHaveKey('duplicate')
        ->and($second['duplicate']['id'])->toBe($first['id'])
        ->and(CmsMedia::query()->count())->toBe(1);

    // «Загрузить копию» is the second answer of that question.
    $this->post(libraryUrl('upload'), ['file' => $file(), 'force' => '1'])->assertOk()->json('media');

    expect(CmsMedia::query()->count())->toBe(2);
});

it('narrows an upload to the kinds the field asked for', function (): void {
    // The same file the library keeps happily when nothing narrows it.
    $svg = fn (): UploadedFile => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="8" height="8"><rect width="8" height="8"/></svg>');

    $this->post(libraryUrl('upload'), [
        'file' => $svg(),
        'allowed_kinds' => ['image'],
    ])->assertStatus(415)->assertJsonStructure(['message']);

    expect(CmsMedia::query()->count())->toBe(0);

    // A name that belongs to no kind is dropped and not read as a narrowing
    // of its own: the request can only take from the allowlist.
    $this->post(libraryUrl('upload'), [
        'file' => $svg(),
        'allowed_kinds' => ['nonsense'],
    ])->assertOk();

    expect(CmsMedia::query()->count())->toBe(1);
});

/* ------------------------------------------------------------------ picker */

it('draws the dialog of the picker out of the partials of the section', function (): void {
    $folder = libraryTreeFolder('Афиши');
    libraryMedia(['name' => 'Летняя сцена', 'folder_id' => $folder->id]);

    $html = $this->get(libraryUrl('picker').'?'.http_build_query([
        'kinds' => ['image', 'vector'],
        'target' => 'field-1',
        'scope' => 'content[hero][image]',
    ]))->assertOk()->getContent();

    expect($html)->toContain('cmsMediaPicker')
        ->and($html)->toContain('Все файлы')
        ->and($html)->toContain('Афиши')
        ->and($html)->toContain((string) __('moonshine-media::library.picker.submit'))
        ->and($html)->toContain('field-1')
        // The two chips of the kinds it takes, beside «Все», and none of the
        // three it does not. The words of every kind travel in `labels` all
        // the same, so the assertion is about the chips and not about the
        // document.
        ->and($html)->toContain('>Фото</button>')
        ->and($html)->toContain('>Иконки</button>')
        ->and($html)->not->toContain('>Документы</button>')
        // No details panel and nothing that renames, moves or deletes: the
        // words of the whole section travel in `labels` all the same, so the
        // assertion is about the markup.
        ->and($html)->not->toContain('cms-library__panel')
        ->and($html)->not->toContain('cms-tree__dropdown')
        ->and($html)->not->toContain('cms-folder-new');
});

it('refuses to echo a name of the asking field that is not a name', function (): void {
    $html = $this->get(libraryUrl('picker').'?'.http_build_query([
        'target' => '"><script>alert(1)</script>',
        'scope' => 'content[hero]"onload="x',
    ]))->assertOk()->getContent();

    // Both are written into the page — one as an attribute, one into the
    // script — so both are narrowed to what a name may be made of. A dialog
    // that answers nobody is the safe half of the two.
    expect($html)->not->toContain('<script>alert(1)</script>')
        ->and($html)->not->toContain('onload="x')
        ->and($html)->toContain('data-cms-media-picker=""')
        ->and($html)->toContain("target: ''");

    // And a name that is a name travels whole.
    $ok = $this->get(libraryUrl('picker').'?'.http_build_query([
        'target' => 'cms-media-ab12cd34',
        'scope' => 'content[slides][n][image]',
    ]))->assertOk()->getContent();

    expect($ok)->toContain('data-cms-media-picker="cms-media-ab12cd34"')
        ->and($ok)->toContain('content[slides][n][image]');
});

it('hides the row of chips where the field takes one kind of file', function (): void {
    $html = $this->get(libraryUrl('picker').'?'.http_build_query(['kinds' => ['image']]))
        ->assertOk()
        ->getContent();

    // The file dialog offers the extensions of that one kind and no other:
    // `@js` writes the list of kinds itself as escaped javascript, and what
    // the narrowing actually does is visible here.
    expect($html)->toContain('.jpg')
        ->and($html)->not->toContain('.svg')
        ->and($html)->not->toContain('>Иконки</button>')
        ->and($html)->not->toContain('>Все</button>');
});

it('carries the flag of several files and the file already chosen', function (): void {
    $folder = libraryTreeFolder('Афиши');
    $media = libraryMedia(['name' => 'Летняя сцена', 'folder_id' => $folder->id]);

    $html = $this->get(libraryUrl('picker').'?'.http_build_query([
        'multiple' => '1',
        'selected' => $media->uuid,
    ]))->assertOk()->getContent();

    expect($html)->toContain('multiple: true')
        ->and($html)->toContain($media->uuid)
        // The dialog opens where the chosen file lies and not at the top of
        // the library.
        ->and($html)->toContain('startFolder: '.$folder->id);
});

it('opens on the folder the field remembered, whatever the file says', function (): void {
    $other = libraryTreeFolder('Иконки');
    $media = libraryMedia(['folder_id' => libraryTreeFolder('Афиши')->id]);

    $html = $this->get(libraryUrl('picker').'?'.http_build_query([
        'selected' => $media->uuid,
        'folder' => $other->id,
    ]))->assertOk()->getContent();

    expect($html)->toContain('startFolder: '.$other->id);
});

/* ----------------------------------------------------------------- details */

it('tells about one file in words, with the places it stands in', function (): void {
    $media = libraryMedia(['metadata' => ['alt' => ['ru' => 'Афиша фестиваля']]]);
    $owner = libraryOwner('Фестиваль');

    CmsMediaUsage::query()->create([
        'cms_media_id' => $media->id,
        'usable_type' => GalleryOwner::MORPH_ALIAS,
        'usable_id' => $owner->getKey(),
        'field' => 'image',
        'path' => 'content.image',
    ]);

    $details = $this->get(libraryUrl('show', ['uuid' => $media->uuid]))->assertOk()->json('media');

    expect($details['alt']['ru'])->toBe('Афиша фестиваля')
        ->and($details['size_human'])->toBe('2,4 МБ')
        ->and($details['usages'])->toHaveCount(1)
        ->and($details['usages'][0]['type'])->toBe('Площадка')
        ->and($details['usages'][0]['title'])->toContain('Фестиваль')
        // The panel builds the address of the admin; the core of the library
        // hands over the alias and the key and never an address.
        ->and($details['usages'][0]['url'])->toContain('gallery-owner-resource')
        ->and($details['usages'][0]['places'])->toBe('1 место');
});

it('follows the address a resolver of the installation brought, and builds one only when it did not', function (): void {
    $links = app(UsageLinks::class);

    // A record of the installation — a tour, a venue — may know its own
    // screen, and then the registry is not asked at all.
    $own = new UsageOwnerLabel(
        title: 'Rammstein',
        url: '/admin/resource/tour-resource/form/17',
        type: 'tour',
        ownerType: 'tour',
        ownerId: 17,
    );

    expect($links->url($own))->toBe('/admin/resource/tour-resource/form/17');

    // An empty address is no address: the record a registered resource
    // edits is still the one this package builds.
    $owner = libraryOwner('О нас');
    $ours = new UsageOwnerLabel(
        title: 'О нас',
        url: '',
        type: 'venue',
        ownerType: GalleryOwner::MORPH_ALIAS,
        ownerId: $owner->getKey(),
    );

    expect($links->url($ours))->toContain('gallery-owner-resource');

    // Neither an address nor an alias the registry knows: the line stays
    // plain text and never a broken link.
    $stranger = new UsageOwnerLabel(title: 'Что-то', type: 'tour', ownerType: 'tour', ownerId: 3);

    expect($links->url($stranger))->toBeNull();
});

it('says a file is gone rather than showing an error page', function (): void {
    $this->get(libraryUrl('show', ['uuid' => 'a-uuid-nobody-wrote']))->assertNotFound();
});

it('renames a file and writes the description of every language', function (): void {
    $media = libraryMedia();

    $body = $this->post(libraryUrl('update', ['uuid' => $media->uuid]), [
        'name' => 'Афиша фестиваля',
        'alt' => ['ru' => 'Сцена вечером', 'en' => 'The stage at night'],
        'caption' => ['ru' => 'Открытие'],
    ])->assertOk()->json();

    expect($body['media']['name'])->toBe('Афиша фестиваля')
        ->and($body['media']['has_alt'])->toBeTrue()
        ->and($body['media']['caption']['ru'])->toBe('Открытие')
        ->and($media->refresh()->metadata['alt'])->toBe(['ru' => 'Сцена вечером', 'en' => 'The stage at night']);
});

it('refuses a name that is nothing at all', function (): void {
    $media = libraryMedia();

    $this->post(libraryUrl('update', ['uuid' => $media->uuid]), ['name' => '  '])
        ->assertStatus(422)
        ->assertJsonStructure(['message']);
});

/* ---------------------------------------------------------------- replacing */

it('replaces a file with one of the same kind and refuses one of another', function (): void {
    $uuid = $this->post(libraryUrl('upload'), [
        'file' => UploadedFile::fake()->image('poster.jpg', 600, 400),
    ])->assertOk()->json('media.id');

    $this->post(libraryUrl('replace', ['uuid' => $uuid]), [
        'file' => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="8" height="8"><rect width="8" height="8"/></svg>'),
    ])->assertStatus(415)->assertJsonStructure(['message']);

    $body = $this->post(libraryUrl('replace', ['uuid' => $uuid]), [
        'file' => UploadedFile::fake()->image('another.png', 300, 200),
    ])->assertOk()->json();

    expect($body['media']['id'])->toBe($uuid)
        ->and(CmsMedia::query()->where('uuid', $uuid)->value('version'))->toBe(2);
});

/* ------------------------------------------------------------ moving, going */

it('moves the selected files into a folder', function (): void {
    $folder = libraryTreeFolder('Афиши');
    $first = libraryMedia();
    $second = libraryMedia(['name' => 'Второй']);

    $this->post(libraryUrl('move'), [
        'uuids' => [$first->uuid, $second->uuid],
        'folder' => $folder->id,
    ])->assertOk()->assertJson(['ok' => true]);

    expect($first->refresh()->folder_id)->toBe($folder->id)
        ->and($second->refresh()->folder_id)->toBe($folder->id);
});

it('refuses a move into a folder that is not there instead of using the root', function (): void {
    $media = libraryMedia();

    $this->post(libraryUrl('move'), ['uuids' => [$media->uuid], 'folder' => 9999])
        ->assertNotFound()
        ->assertJsonStructure(['message']);

    // The quiet reading of an unknown identifier would have moved it here.
    expect($media->refresh()->folder_id)->toBeNull();
});

it('refuses a move that names no file at all', function (): void {
    $this->post(libraryUrl('move'), ['uuids' => [], 'folder' => null])
        ->assertStatus(422)
        ->assertJsonStructure(['message']);
});

it('refuses an upload into a folder that is not there', function (): void {
    $this->post(libraryUrl('upload'), [
        'file' => UploadedFile::fake()->image('poster.jpg', 60, 40),
        'folder' => 9999,
    ])->assertNotFound()->assertJsonStructure(['message']);

    expect(CmsMedia::query()->count())->toBe(0);
});

it('refuses a folder under a parent that is not there', function (): void {
    $this->post(libraryUrl('folders.create'), ['name' => 'Афиши', 'parent' => 9999])
        ->assertNotFound()
        ->assertJsonStructure(['message']);

    expect(CmsMediaFolder::query()->count())->toBe(0);
});

it('refuses a description under a key that is no language', function (): void {
    $media = libraryMedia();

    $this->post(libraryUrl('update', ['uuid' => $media->uuid]), [
        'alt' => ['<script>' => 'Афиша'],
    ])->assertStatus(422)->assertJsonStructure(['message']);

    expect($media->refresh()->metadata ?? [])->not->toHaveKey('alt');
});

it('keeps a name with angle brackets as the text it is', function (): void {
    $media = libraryMedia();
    $name = 'Сцена <b>вечером</b> & "фестиваль"';

    $tile = $this->post(libraryUrl('update', ['uuid' => $media->uuid]), ['name' => $name])
        ->assertOk()
        ->json('media');

    // It travels as text in json and is drawn by `x-text`, so nothing on the
    // way escapes or unescapes it: what came back is what was typed.
    expect($tile['name'])->toBe($name)
        ->and($media->refresh()->name)->toBe($name);
});

it('refuses to delete a file that stands on a page and says where it stands', function (): void {
    $media = libraryMedia();
    $owner = libraryOwner('Главная');

    CmsMediaUsage::query()->create([
        'cms_media_id' => $media->id,
        'usable_type' => GalleryOwner::MORPH_ALIAS,
        'usable_id' => $owner->getKey(),
        'field' => 'image',
        'path' => 'content.image',
    ]);

    $body = $this->post(libraryUrl('delete'), ['uuids' => [$media->uuid]])
        ->assertStatus(409)
        ->json();

    expect($body['usages'][0]['title'])->toContain('Главная')
        // The sentence of the library names the record by its uuid, which is
        // a line for a log: the panel says the places instead.
        ->and($body['message'])->not->toContain($media->uuid)
        ->and(CmsMedia::query()->count())->toBe(1);
});

it('deletes a selection and reports what stayed behind', function (): void {
    $free = libraryMedia(['name' => 'Свободный']);
    $used = libraryMedia(['name' => 'Занятый']);

    CmsMediaUsage::query()->create([
        'cms_media_id' => $used->id,
        'usable_type' => GalleryOwner::MORPH_ALIAS,
        'usable_id' => libraryOwner('Главная')->getKey(),
        'field' => 'image',
        'path' => 'content.image',
    ]);

    $body = $this->post(libraryUrl('delete'), ['uuids' => [$free->uuid, $used->uuid]])
        ->assertOk()
        ->json();

    expect($body['report']['deleted'])->toBe([$free->uuid])
        ->and($body['report']['in_use'])->toBe([$used->uuid])
        ->and($body['message'])->toBe('Удалено: 1. 1 файл используется и остался.')
        ->and(CmsMedia::query()->count())->toBe(1);
});

/* ----------------------------------------------------------------- folders */

it('creates, renames, moves and deletes a folder', function (): void {
    $created = $this->post(libraryUrl('folders.create'), ['name' => 'Афиши'])->assertOk()->json();
    $id = $created['folder']['id'];

    expect($created['tree']['folders'][0]['name'])->toBe('Афиши');

    $this->post(libraryUrl('folders.rename', ['folder' => $id]), ['name' => 'Постеры'])->assertOk();

    $parent = libraryTreeFolder('Архив');

    $this->post(libraryUrl('folders.move', ['folder' => $id]), ['parent' => $parent->id])->assertOk();

    expect(CmsMediaFolder::query()->find($id)?->parent_id)->toBe($parent->id);

    $this->post(libraryUrl('folders.delete', ['folder' => $id]))->assertOk();

    expect(CmsMediaFolder::query()->find($id))->toBeNull();
});

it('refuses a second folder of the same name under one parent', function (): void {
    libraryTreeFolder('Афиши');

    $this->post(libraryUrl('folders.create'), ['name' => 'Афиши'])
        ->assertStatus(409)
        ->assertJsonStructure(['message']);
});

it('refuses to delete a folder that is not empty', function (): void {
    $folder = libraryTreeFolder('Афиши');
    libraryMedia(['folder_id' => $folder->id]);

    $this->post(libraryUrl('folders.delete', ['folder' => $folder->id]))
        ->assertStatus(409)
        ->assertJsonStructure(['message']);

    expect(CmsMediaFolder::query()->count())->toBe(1);
});

/* -------------------------------------------------------------- the screen */

it('draws the section with its tree, its filters and nothing technical', function (): void {
    $html = $this->get(route('moonshine.page', ['pageUri' => 'cms-media']))->assertOk()->getContent();

    expect($html)->toContain('cmsMediaLibrary')
        ->and($html)->toContain('Все файлы')
        ->and($html)->toContain('Без описания')
        ->and($html)->toContain('Загрузить')
        ->and(plainUrls($html))->toContain('cms/media/items')
        // The words of the client come from the dictionary of the panel.
        ->and($html)->toContain('нет описания');
});

it('names a kind of usage by what was registered, then by the dictionary, then as a record', function (): void {
    $links = app(UsageLinks::class);
    $label = static fn (string $type): UsageOwnerLabel => new UsageOwnerLabel(title: 'Что-то', type: $type);

    // An application lays the kinds of its own records over the dictionary
    // of the package, the way `lang/vendor/moonshine-media` does. The file of
    // the package is read first: lines added to a group that is not loaded
    // yet would stand in for the whole of it.
    __('moonshine-media::library.title');
    app('translator')->addLines(['library.usage_types.tour' => 'Тур'], 'ru', 'moonshine-media');

    // A key that has no translation is no caption: the next source is asked.
    app(UsageLinkRegistry::class)->caption('festival', 'nobody::wrote.this');

    expect($links->caption($label('venue')))->toBe('Площадка')
        ->and($links->caption($label('tour')))->toBe('Тур')
        ->and($links->caption($label('festival')))->toBe('Запись')
        ->and($links->caption($label('cms_block')))->toBe('Запись');
});
