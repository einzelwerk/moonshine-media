<?php

declare(strict_types=1);

use Ewk\Cms\Media\Enums\FileKind;
use Ewk\Cms\Media\Exceptions\InvalidMediaLocaleException;
use Ewk\Cms\Media\Models\CmsMedia;
use Ewk\Cms\Media\Queries\MediaQuery;
use Ewk\Cms\Media\Queries\MediaSort;
use Ewk\Cms\Media\Tests\Fixtures\LibraryFactory;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Walk a listing to its end and collect the uuids page by page, which is the
 * only way to find out whether a cursor loses a row or shows it twice.
 *
 * @return list<string>
 */
function walkPages(MediaQuery $query, int $perPage, int &$pages = 0): array
{
    $uuids = [];
    $cursor = null;
    $pages = 0;

    do {
        $page = $query->limit($perPage)->after($cursor)->get();
        $pages++;

        foreach ($page->items as $item) {
            $uuids[] = $item->uuid;
        }

        $cursor = $page->nextCursor;
    } while ($cursor !== null && $pages < 20);

    return $uuids;
}

it('counts the usages instead of loading them', function (): void {
    $media = LibraryFactory::media();
    LibraryFactory::usage($media, 'cms_block', 1, 'content', 'items.0.image');
    LibraryFactory::usage($media, 'cms_block', 2, 'content', 'image');

    $found = MediaQuery::make()->get()->items[0];

    expect($found->getAttribute('usages_count'))->toBe(2)
        ->and($found->relationLoaded('usages'))->toBeFalse();
});

it('searches the name, the name the file arrived with and the metadata', function (): void {
    $byName = LibraryFactory::media(['name' => 'Afisha sezona', 'original_name' => 'img-1.jpg']);
    $byOriginal = LibraryFactory::media(['name' => 'Nothing', 'original_name' => 'afisha-final.jpg']);
    $byAlt = LibraryFactory::media(['name' => 'Nothing', 'original_name' => 'img-2.jpg', 'metadata' => ['alt' => ['ru' => 'Афиша сезона']]]);
    LibraryFactory::media(['name' => 'Logo', 'original_name' => 'logo.svg']);

    $found = array_map(
        static fn (CmsMedia $media): string => $media->uuid,
        MediaQuery::make()->search('afisha')->get()->items,
    );

    expect($found)->toHaveCount(2)
        ->and($found)->toContain($byName->uuid, $byOriginal->uuid);

    $cyrillic = MediaQuery::make()->search('Афиша')->get()->items;

    expect($cyrillic)->toHaveCount(1)
        ->and($cyrillic[0]->uuid)->toBe($byAlt->uuid);
});

it('treats the wildcards of LIKE as characters somebody typed', function (): void {
    $percent = LibraryFactory::media(['name' => 'Skidka 100%', 'original_name' => 'sale.jpg']);
    LibraryFactory::media(['name' => 'Skidka 1009', 'original_name' => 'other.jpg']);
    $underscore = LibraryFactory::media(['name' => 'logo_dark', 'original_name' => 'l.png']);
    LibraryFactory::media(['name' => 'logoXdark', 'original_name' => 'x.png']);

    $found = MediaQuery::make()->search('100%')->get()->items;
    expect($found)->toHaveCount(1)->and($found[0]->uuid)->toBe($percent->uuid);

    $found = MediaQuery::make()->search('logo_dark')->get()->items;
    expect($found)->toHaveCount(1)->and($found[0]->uuid)->toBe($underscore->uuid);

    // The escape character itself is a character too.
    $bang = LibraryFactory::media(['name' => 'Wow!', 'original_name' => 'wow.jpg']);
    $found = MediaQuery::make()->search('Wow!')->get()->items;
    expect($found)->toHaveCount(1)->and($found[0]->uuid)->toBe($bang->uuid);
});

it('tells the files of one folder from the files of no folder at all', function (): void {
    $folder = LibraryFactory::folder('Afisha');
    $inside = LibraryFactory::media(['folder_id' => $folder->id]);
    $outside = LibraryFactory::media();

    expect(MediaQuery::make()->inFolder($folder->id)->get()->items)->toHaveCount(1)
        ->and(MediaQuery::make()->inFolder($folder->id)->get()->items[0]->uuid)->toBe($inside->uuid)
        ->and(MediaQuery::make()->inFolder(null, true)->get()->items[0]->uuid)->toBe($outside->uuid)
        ->and(MediaQuery::make()->inFolder(null)->get()->items)->toHaveCount(2);
});

it('filters by one kind and by several', function (): void {
    LibraryFactory::media(['kind' => FileKind::IMAGE]);
    LibraryFactory::media(['kind' => FileKind::VECTOR]);
    LibraryFactory::media(['kind' => FileKind::VIDEO]);

    expect(MediaQuery::make()->ofKind(FileKind::VECTOR)->get()->items)->toHaveCount(1)
        ->and(MediaQuery::make()->ofKind([FileKind::IMAGE, FileKind::VECTOR])->get()->items)->toHaveCount(2)
        ->and(MediaQuery::make()->ofKind(null)->get()->items)->toHaveCount(3);
});

it('lists the pictures nobody described in the language being worked in', function (): void {
    $noMetadata = LibraryFactory::media(['metadata' => null]);
    $emptyAlt = LibraryFactory::media(['metadata' => ['alt' => ['ru' => '']]]);
    $otherLanguage = LibraryFactory::media(['metadata' => ['alt' => ['en' => 'Poster']]]);
    $vector = LibraryFactory::media(['kind' => FileKind::VECTOR, 'metadata' => null]);
    LibraryFactory::media(['metadata' => ['alt' => ['ru' => 'Афиша']]]);
    // A sound file is not described by alt, so it never joins the list.
    LibraryFactory::media(['kind' => FileKind::AUDIO, 'metadata' => null]);

    $found = array_map(
        static fn (CmsMedia $media): string => $media->uuid,
        MediaQuery::make()->withoutAlt('ru')->get()->items,
    );

    expect($found)->toHaveCount(4)
        ->and($found)->toContain($noMetadata->uuid, $emptyAlt->uuid, $otherLanguage->uuid, $vector->uuid);
});

it('refuses a language code that is not one', function (): void {
    expect(fn () => MediaQuery::make()->withoutAlt('ru\'); drop'))
        ->toThrow(InvalidMediaLocaleException::class);

    expect(MediaQuery::make()->withoutAlt('en-GB')->get()->items)->toBe([]);
});

it('lists what nothing points at', function (): void {
    $used = LibraryFactory::media();
    $free = LibraryFactory::media();
    LibraryFactory::usage($used, 'cms_block', 1);

    $found = MediaQuery::make()->unused()->get()->items;

    expect($found)->toHaveCount(1)->and($found[0]->uuid)->toBe($free->uuid);
});

it('narrows to one owner and ignores a half filled pair', function (): void {
    $mine = LibraryFactory::media(['owner_type' => 'organizer', 'owner_id' => 7]);
    LibraryFactory::media(['owner_type' => 'organizer', 'owner_id' => 8]);
    LibraryFactory::media();

    expect(MediaQuery::make()->ownedBy('organizer', 7)->get()->items)->toHaveCount(1)
        ->and(MediaQuery::make()->ownedBy('organizer', 7)->get()->items[0]->uuid)->toBe($mine->uuid)
        ->and(MediaQuery::make()->ownedBy(null, null)->get()->items)->toHaveCount(3);
});

it('walks three pages of every order without losing or repeating a row', function (): void {
    // Every row shares the sort key of every other one, so nothing but the
    // identifier can put them in a stable order.
    $now = now()->startOfSecond();

    for ($i = 1; $i <= 5; $i++) {
        LibraryFactory::media(['name' => 'Same', 'size' => 500, 'created_at' => $now, 'updated_at' => $now]);
    }

    foreach ([MediaSort::NEWEST, MediaSort::NAME, MediaSort::SIZE_DESC] as $sort) {
        $pages = 0;
        $uuids = walkPages(MediaQuery::make()->sort($sort), 2, $pages);

        $expected = CmsMedia::query()
            ->orderBy('id', $sort === MediaSort::NAME ? 'asc' : 'desc')
            ->pluck('uuid')
            ->all();

        expect($pages)->toBe(3)
            ->and($uuids)->toHaveCount(5)
            ->and($uuids)->toBe($expected);
    }
});

it('orders by what each sort promises', function (): void {
    $old = LibraryFactory::media(['name' => 'Bravo', 'size' => 300, 'created_at' => now()->subDay()]);
    $new = LibraryFactory::media(['name' => 'Alpha', 'size' => 900, 'created_at' => now()]);

    expect(MediaQuery::make()->sort(MediaSort::NEWEST)->get()->items[0]->uuid)->toBe($new->uuid)
        ->and(MediaQuery::make()->sort(MediaSort::NAME)->get()->items[0]->uuid)->toBe($new->uuid)
        ->and(MediaQuery::make()->sort(MediaSort::SIZE_DESC)->get()->items[0]->uuid)->toBe($new->uuid)
        ->and(MediaQuery::make()->sort(MediaSort::NAME)->get()->items[1]->uuid)->toBe($old->uuid);
});

it('hands out no cursor on the last page and ignores a mangled one', function (): void {
    LibraryFactory::media();
    LibraryFactory::media();

    $page = MediaQuery::make()->limit(5)->get();

    expect($page->nextCursor)->toBeNull()
        ->and($page->isEmpty())->toBeFalse()
        ->and(MediaQuery::make()->after('not-a-cursor')->get()->items)->toHaveCount(2);
});

it('keeps the limit inside the bounds the grid can draw', function (): void {
    for ($i = 0; $i < 3; $i++) {
        LibraryFactory::media();
    }

    expect(MediaQuery::make()->limit(0)->get()->items)->toHaveCount(1)
        ->and(MediaQuery::make()->limit(1000)->get()->items)->toHaveCount(3);
});

it('leaves the builder it was asked from alone', function (): void {
    LibraryFactory::media(['kind' => FileKind::IMAGE]);
    LibraryFactory::media(['kind' => FileKind::VIDEO]);

    $all = MediaQuery::make();
    $images = $all->ofKind(FileKind::IMAGE);

    expect($all->get()->items)->toHaveCount(2)
        ->and($images->get()->items)->toHaveCount(1);
});

it('counts the files of every folder in one query, the root included', function (): void {
    $afisha = LibraryFactory::folder('Afisha');
    $archive = LibraryFactory::folder('Archive');

    LibraryFactory::media(['folder_id' => $afisha->id]);
    LibraryFactory::media(['folder_id' => $afisha->id]);
    LibraryFactory::media(['folder_id' => $archive->id]);
    LibraryFactory::media();

    $counts = MediaQuery::countsByFolder();

    expect($counts[$afisha->id])->toBe(2)
        ->and($counts[$archive->id])->toBe(1)
        ->and($counts[MediaQuery::ROOT])->toBe(1);
});

it('finds a record by its uuid and says so when there is none', function (): void {
    $media = LibraryFactory::media();

    expect(MediaQuery::find($media->uuid)?->id)->toBe($media->id)
        ->and(MediaQuery::find('9f3c-nothing'))->toBeNull()
        ->and(MediaQuery::findOrFail($media->uuid)->id)->toBe($media->id)
        ->and(fn () => MediaQuery::findOrFail('9f3c-nothing'))->toThrow(ModelNotFoundException::class);
});
