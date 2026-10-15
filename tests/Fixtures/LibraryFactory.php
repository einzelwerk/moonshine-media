<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Tests\Fixtures;

use Ewk\Cms\Media\Enums\FileKind;
use Ewk\Cms\Media\Enums\MediaVariantState;
use Ewk\Cms\Media\Models\CmsMedia;
use Ewk\Cms\Media\Models\CmsMediaFolder;
use Ewk\Cms\Media\Models\CmsMediaUsage;
use Ewk\Cms\Media\Models\CmsMediaVariant;
use Ewk\Cms\Media\Support\StoragePath;
use Illuminate\Support\Str;

/**
 * Rows of the library for the feature suites.
 *
 * A class and not the global helpers of a test file: several files need the
 * same fixtures, and Pest loads every one of them into the same process.
 */
final class LibraryFactory
{
    private function __construct()
    {
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function media(array $attributes = []): CmsMedia
    {
        $uuid = $attributes['uuid'] ?? (string) Str::uuid();
        $version = $attributes['version'] ?? 1;

        return CmsMedia::query()->create(array_merge([
            'uuid' => $uuid,
            'disk' => 'public',
            'path' => StoragePath::original($uuid, (int) $version, 'jpg'),
            'original_name' => 'poster.jpg',
            'name' => 'Poster',
            'mime' => 'image/jpeg',
            'size' => 1024,
            'kind' => FileKind::IMAGE,
            'version' => $version,
        ], $attributes));
    }

    public static function folder(string $name, ?CmsMediaFolder $parent = null): CmsMediaFolder
    {
        return CmsMediaFolder::query()->create([
            'name' => $name,
            'parent_id' => $parent?->id,
        ]);
    }

    public static function variant(CmsMedia $media, string $preset = 'thumb'): CmsMediaVariant
    {
        return CmsMediaVariant::query()->create([
            'cms_media_id' => $media->id,
            'preset' => $preset,
            'disk' => $media->disk,
            'path' => StoragePath::variant($media->uuid, $media->version, $preset, 'webp'),
            'format' => 'webp',
            'state' => MediaVariantState::READY,
        ]);
    }

    public static function usage(
        CmsMedia $media,
        string $usableType,
        int $usableId,
        string $field = 'content',
        ?string $path = null,
    ): CmsMediaUsage {
        return CmsMediaUsage::query()->create([
            'cms_media_id' => $media->id,
            'usable_type' => $usableType,
            'usable_id' => $usableId,
            'field' => $field,
            'path' => $path ?? $field,
        ]);
    }
}
