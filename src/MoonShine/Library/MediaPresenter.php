<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\MoonShine\Library;

use DateTimeInterface;
use Ewk\Cms\Media\Enums\FileKind;
use Ewk\Cms\Media\Localization\LanguageCatalog;
use Ewk\Cms\Media\Models\CmsMedia;
use Ewk\Cms\Media\MoonShine\Support\DisplayTime;
use Ewk\Cms\Media\Support\DiskRegistry;
use Ewk\Cms\Media\Usages\MediaUsages;
use Ewk\Cms\Media\Usages\UsageGroup;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use MoonShine\Laravel\MoonShineAuth;
use Throwable;

/**
 * What the library hands the browser about one file.
 *
 * Two shapes and one rule. A tile is what the grid draws; the details are the
 * tile plus everything the panel on the right shows. The rule is that neither
 * of them carries a uuid of a storage, a path, a MIME string or a checksum:
 * an editor reads a name, a size in words and a date, and a technical string
 * on a screen is a question they cannot answer.
 *
 * The `id` is the uuid all the same, because that is the only handle the
 * addresses of this section take. It travels and is never printed.
 */
final readonly class MediaPresenter
{
    public function __construct(
        private LanguageCatalog $languages,
        private MediaUsages $usages,
        private UsageLinks $links,
        private DiskRegistry $disks,
    ) {
    }

    /**
     * The rows of one page of the grid. The conversions are loaded for the
     * whole page at once: asking a record for its thumbnail loads them one by
     * one otherwise, which is sixty queries a scroll.
     *
     * @param  list<CmsMedia>  $items
     * @return list<array<string, mixed>>
     */
    public function tiles(array $items): array
    {
        if ($items === []) {
            return [];
        }

        (new EloquentCollection($items))->loadMissing('variants');

        return array_map(fn (CmsMedia $media): array => $this->tile($media), $items);
    }

    /**
     * @return array<string, mixed>
     */
    public function tile(CmsMedia $media): array
    {
        $media->loadMissing('variants');
        $usages = self::usagesCount($media);

        return [
            'id' => $media->uuid,
            'name' => self::name($media),
            'kind' => $media->kind->value,
            'kind_label' => (string) __('moonshine-media::library.kinds.'.$media->kind->value),
            'ext' => self::extension($media),
            'size_human' => HumanSize::format($media->size),
            'width' => $media->width,
            'height' => $media->height,
            'thumb_url' => self::thumbUrl($media),
            'url' => $media->url(),
            'has_alt' => $this->hasAlt($media),
            'usages_count' => $usages,
            // The list prints the places in words, the way the tile prints
            // its badge: a bare «0» in a column called «Где стоит» is a
            // number an editor has to decode.
            'usages_label' => $usages === 0
                ? (string) __('moonshine-media::library.badges.unused')
                : trans_choice('moonshine-media::library.places', $usages, ['count' => $usages]),
            'folder_id' => $media->folder_id,
            'created_at_human' => self::date($media),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function details(CmsMedia $media): array
    {
        $groups = $this->usages->of($media);
        $disk = $this->disks->has($media->disk) ? $this->disks->get($media->disk)->label : null;

        return $this->tile($media) + [
            'alt' => self::dictionary($media, 'alt'),
            'caption' => self::dictionary($media, 'caption'),
            'folder_id' => $media->folder_id,
            'usages' => $this->usageRows($groups),
            'uploader' => self::uploader($media),
            'storage' => $this->disks->isSingle() ? null : $disk,
            'downloadable' => true,
        ];
    }

    /**
     * The places a file stands in, as the panel prints them: the name of the
     * record, an address inside the admin when one can be built, and how many
     * spots inside it hold the file.
     *
     * @param  list<UsageGroup>  $groups
     * @return list<array{title: string, type: string, url: string|null, places: string, count: int}>
     */
    public function usageRows(array $groups): array
    {
        $rows = [];

        foreach ($groups as $group) {
            $count = count($group->fields());

            $rows[] = [
                'title' => $group->owner->title,
                'type' => $this->links->caption($group->owner),
                'url' => $this->links->url($group->owner),
                'places' => trans_choice('moonshine-media::library.places', $count, ['count' => $count]),
                'count' => $count,
            ];
        }

        return $rows;
    }

    private function hasAlt(CmsMedia $media): bool
    {
        if (! in_array($media->kind, [FileKind::IMAGE, FileKind::VECTOR], true)) {
            // Only a picture is described by a caption, so nothing else is
            // ever marked as missing one.
            return true;
        }

        $alt = self::dictionary($media, 'alt');
        $locale = $this->languages->defaultLocale();

        return trim($alt[$locale] ?? '') !== '';
    }

    /**
     * @return array<string, string>
     */
    private static function dictionary(CmsMedia $media, string $key): array
    {
        $metadata = $media->metadata ?? [];
        $values = $metadata[$key] ?? null;

        if (! is_array($values)) {
            return [];
        }

        $dictionary = [];

        foreach ($values as $locale => $value) {
            if (is_string($locale) && is_scalar($value)) {
                $dictionary[$locale] = (string) $value;
            }
        }

        return $dictionary;
    }

    private static function name(CmsMedia $media): string
    {
        $name = trim((string) ($media->name ?? ''));

        if ($name !== '') {
            return $name;
        }

        $original = pathinfo((string) ($media->original_name ?? ''), PATHINFO_FILENAME);

        return $original === '' ? (string) __('moonshine-media::library.details.title') : $original;
    }

    /**
     * The format, as a person names it: «JPG». It is read off the path,
     * because the server decides the extension a file is stored under and the
     * name the editor typed has nothing to do with it.
     */
    private static function extension(CmsMedia $media): string
    {
        return mb_strtoupper(pathinfo($media->path, PATHINFO_EXTENSION));
    }

    private static function thumbUrl(CmsMedia $media): ?string
    {
        return match ($media->kind) {
            FileKind::IMAGE => $media->resolvedVariantUrl(ThumbnailPreset::name()),
            FileKind::VECTOR => $media->url(),
            // The frame, once the installation cuts one, see
            // `Ewk\Cms\Media\Contracts\VideoFramesContract`; never the video.
            FileKind::VIDEO => $media->previewUrl(ThumbnailPreset::name()),
            default => null,
        };
    }

    /**
     * The grid reads the number off `withCount`, and a record that came from
     * somewhere else — the upload, a replacement — has no such column. It is
     * asked for by reading the raw attributes and not the accessor: an
     * application that turns on `preventAccessingMissingAttributes` throws on
     * a column that is not there, and «not counted yet» is not an error.
     */
    private static function usagesCount(CmsMedia $media): int
    {
        if (! array_key_exists('usages_count', $media->getAttributes())) {
            $media->loadCount('usages');
        }

        $count = $media->getAttributes()['usages_count'] ?? null;

        return is_numeric($count) ? (int) $count : 0;
    }

    private static function date(CmsMedia $media): string
    {
        $created = $media->getAttribute('created_at');

        if (! $created instanceof DateTimeInterface) {
            return '';
        }

        // «22 сент. 2026» and not a timestamp: the language of the panel
        // decides the month, so the instance is told which one it is instead
        // of trusting whatever the global of Carbon happens to hold. The day
        // is the one of the panel's clock, not of the database.
        $date = DisplayTime::local($created);
        $date->locale(app()->getLocale());

        return $date->isoFormat('D MMM YYYY');
    }

    /**
     * Who uploaded it, when the panel can still name them. The identifier is
     * a user of the panel and not a model of this package, so it is read
     * through the provider of the guard and never through a class name.
     */
    private static function uploader(CmsMedia $media): ?string
    {
        if ($media->uploaded_by === null) {
            return null;
        }

        try {
            $user = MoonShineAuth::getGuard()->getProvider()->retrieveById($media->uploaded_by);
        } catch (Throwable) {
            return null;
        }

        if ($user === null) {
            return null;
        }

        $name = $user->getAttribute('name');

        return is_string($name) && $name !== '' ? $name : null;
    }
}
