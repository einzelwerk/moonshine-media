<?php

declare(strict_types=1);

use Ewk\Cms\Media\Enums\FileKind;
use Ewk\Cms\Media\Models\CmsMedia;
use Ewk\Cms\Media\Models\CmsMediaUsage;
use Illuminate\Support\Facades\Schema;

it('names a file after the file that was uploaded, without its extension', function (): void {
    $media = CmsMedia::query()->create([
        'disk' => 'public',
        'path' => 'ab/photo.jpg',
        'original_name' => 'Opening night.JPG',
        'mime' => 'image/jpeg',
        'size' => 10,
    ]);

    expect($media->name)->toBe('Opening night');
});

it('keeps the name it was given', function (): void {
    $media = CmsMedia::query()->create([
        'disk' => 'public',
        'path' => 'ab/photo.jpg',
        'original_name' => 'DSC_0001.jpg',
        'name' => 'Poster',
        'mime' => 'image/jpeg',
        'size' => 10,
    ]);

    expect($media->name)->toBe('Poster');
});

it('leaves the name empty when there is no original name to take it from', function (): void {
    $media = CmsMedia::query()->create([
        'disk' => 'public',
        'path' => 'ab/photo.jpg',
        'mime' => 'image/jpeg',
        'size' => 10,
    ]);

    expect($media->name)->toBeNull();
});

it('calls a row an image until something says otherwise', function (): void {
    // Everything stored before the library existed came from the block editor
    // and was a picture, so the default of the column is also its backfill.
    $media = CmsMedia::query()->create([
        'disk' => 'public',
        'path' => 'ab/photo.jpg',
        'mime' => 'image/jpeg',
        'size' => 10,
    ]);

    expect($media->fresh()->kind)->toBe(FileKind::IMAGE)
        ->and($media->fresh()->version)->toBe(1);
});

it('finds the files of one owner and no one else', function (): void {
    $attributes = ['disk' => 'public', 'mime' => 'image/jpeg', 'size' => 10];

    CmsMedia::query()->create($attributes + ['path' => 'a/1.jpg', 'owner_type' => 'contractor', 'owner_id' => 1]);
    CmsMedia::query()->create($attributes + ['path' => 'a/2.jpg', 'owner_type' => 'contractor', 'owner_id' => 2]);
    CmsMedia::query()->create($attributes + ['path' => 'a/3.jpg']);

    expect(CmsMedia::query()->ownedBy('contractor', 1)->pluck('path')->all())->toBe(['a/1.jpg'])
        ->and(CmsMedia::query()->ownedBy('contractor', 9)->count())->toBe(0);
});

it('carries a position on a usage and leaves it empty for a block', function (): void {
    $media = CmsMedia::query()->create([
        'disk' => 'public', 'path' => 'ab/photo.jpg', 'mime' => 'image/jpeg', 'size' => 10,
    ]);

    $block = CmsMediaUsage::query()->create([
        'cms_media_id' => $media->id,
        'usable_type' => 'cms_page',
        'usable_id' => 1,
        'field' => 'content',
        'path' => 'items.0.image',
    ]);

    $gallery = CmsMediaUsage::query()->create([
        'cms_media_id' => $media->id,
        'usable_type' => 'venue',
        'usable_id' => 1,
        'field' => 'gallery',
        'path' => 'gallery',
        'position' => 3,
    ]);

    expect($block->fresh()->position)->toBeNull()
        ->and($gallery->fresh()->position)->toBe(3);
});

it('rolls the migrations of the library back to the schema that was there before', function (): void {
    expect(Schema::hasTable('cms_media_folders'))->toBeTrue()
        ->and(Schema::hasColumn('cms_media', 'folder'))->toBeFalse();

    $this->artisan('migrate:rollback', ['--step' => 4])->assertExitCode(0);

    expect(Schema::hasTable('cms_media_folders'))->toBeFalse()
        ->and(Schema::hasColumn('cms_media_usages', 'position'))->toBeFalse()
        ->and(Schema::hasColumn('cms_media', 'kind'))->toBeFalse()
        ->and(Schema::hasColumn('cms_media', 'folder_id'))->toBeFalse()
        ->and(Schema::hasColumn('cms_media', 'version'))->toBeFalse()
        ->and(Schema::hasColumn('cms_media', 'uploaded_by'))->toBeFalse()
        ->and(Schema::hasColumn('cms_media', 'owner_type'))->toBeFalse()
        ->and(Schema::hasColumn('cms_media', 'owner_id'))->toBeFalse()
        // What `down()` owes the schema it found: the string column of folders
        // is back, so the migration before this one can run again.
        ->and(Schema::hasColumn('cms_media', 'folder'))->toBeTrue();

    $this->artisan('migrate')->assertExitCode(0);

    expect(Schema::hasTable('cms_media_folders'))->toBeTrue()
        ->and(Schema::hasColumn('cms_media', 'folder'))->toBeFalse()
        ->and(Schema::hasColumn('cms_media', 'kind'))->toBeTrue();
});
