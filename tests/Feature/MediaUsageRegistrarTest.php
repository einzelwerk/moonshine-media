<?php

declare(strict_types=1);

use Ewk\Cms\Media\Models\CmsMedia;
use Ewk\Cms\Media\Models\CmsMediaUsage;
use Ewk\Cms\Media\Services\MediaUsageRegistrar;
use Illuminate\Support\Facades\DB;

function countedQueries(Closure $run): int
{
    $queries = 0;

    DB::listen(static function () use (&$queries): void {
        $queries++;
    });

    $run();

    return $queries;
}

it('writes nothing when there is nothing to write and nothing written before', function (): void {
    // A block of text and a card without a picture are the common case: the
    // transaction and the delete of a rewrite would run on every save of them.
    $queries = countedQueries(static function (): void {
        app(MediaUsageRegistrar::class)->sync('cms_page', 7, 'content', []);
    });

    expect($queries)->toBe(1);
});

it('rewrites the rows of one owner and field, leaving the other fields alone', function (): void {
    $logo = CmsMedia::query()->create(['disk' => 'public', 'path' => 'ab/logo.svg', 'mime' => 'image/svg+xml', 'size' => 10]);
    $cover = CmsMedia::query()->create(['disk' => 'public', 'path' => 'ab/cover.jpg', 'mime' => 'image/jpeg', 'size' => 20]);

    $registrar = app(MediaUsageRegistrar::class);
    $registrar->sync('cms_page', 7, 'content', ['hero.image' => $logo->id]);
    $registrar->sync('cms_page', 7, 'og_image_id', ['og_image_id' => $cover->id]);
    $registrar->sync('cms_page', 7, 'content', []);

    expect(CmsMediaUsage::query()->where('usable_id', 7)->pluck('cms_media_id', 'field')->all())
        ->toBe(['og_image_id' => $cover->id]);
});
