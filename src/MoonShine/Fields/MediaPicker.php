<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\MoonShine\Fields;

use Ewk\Cms\Media\Localization\LanguageCatalog;
use Ewk\Cms\Media\Models\CmsMedia;
use Ewk\Cms\Media\MoonShine\Fields\Concerns\NarrowsToKinds;
use Ewk\Cms\Media\MoonShine\Library\HumanSize;
use Ewk\Cms\Media\MoonShine\Library\MediaAssets;
use Ewk\Cms\Media\MoonShine\Library\MediaPreview;
use Ewk\Cms\Media\MoonShine\Library\PickerModal;
use Illuminate\Container\Container;
use Throwable;

/**
 * One picture as one field with one caption: the thumbnail of what is
 * already stored, its name and its size, the two ways of putting another one
 * there — the library and a file from the desktop — and the description this
 * one place gives it.
 *
 * The stored value is a reference and nothing else: `{"id": "<uuid>",
 * "metadata": {"alt": {"ru": "…"}}}`. There is no deferred upload: a file
 * chosen here reaches the library through the endpoint of the panel the
 * moment it is chosen, which is the whole point: one path of upload means one
 * set of rules, and rules that cannot be walked around.
 *
 * `alt` is translatable whatever the field is, because it is the one property
 * a visitor reads: the picture is the same in every language and a caption in
 * somebody else's is a defect and not a fallback. An empty value here inherits
 * the description of the file itself.
 */
final class MediaPicker extends CompositeValueField
{
    // What this place takes, and the extensions the file dialog offers for
    // it: the same declaration the field of the attachments carries, see
    // {@see NarrowsToKinds}.
    use NarrowsToKinds;

    protected string $view = 'moonshine-media::fields.media-picker';

    protected bool $describable = true;

    /**
     * Do not offer the description of this place. For a field whose writer
     * stores the reference alone — a card picture whose row has a column for
     * the picture and none for its caption — an input that is dropped on the
     * way to the database is worse than no input: an editor fills it and
     * nothing says the words went nowhere.
     */
    public function withoutDescription(): static
    {
        $this->describable = false;

        return $this;
    }

    public function isDescribable(): bool
    {
        return $this->describable;
    }

    /**
     * Drawn on whatever form needs a picture, and no form brings the assets
     * of the field with it, so the field carries them itself, and the library
     * with them: the dialog it opens is the grid of the «Медиа» section.
     * Identical inline assets are merged by the manager, so a page with
     * several media fields pays for one copy.
     *
     * @return list<\MoonShine\Contracts\AssetManager\AssetElementContract>
     */
    protected function assets(): array
    {
        return MediaAssets::inline();
    }

    /**
     * @return array<string, mixed>
     */
    protected function viewData(): array
    {
        $value = $this->getValue();
        $value = is_array($value) ? $value : [];

        $id = $value['id'] ?? null;
        $id = is_string($id) && $id !== '' ? $id : null;
        $media = $id === null ? null : self::mediaOf($id);

        $catalog = self::catalog();
        $locales = $catalog?->locales() ?? [];

        return [
            'picker' => $this,
            'mediaId' => $id,
            'previewUrl' => $media instanceof CmsMedia ? MediaPreview::thumbnail($media) : null,
            'previewName' => $media instanceof CmsMedia ? MediaPreview::fileName($media) : null,
            'previewSize' => $media instanceof CmsMedia ? HumanSize::format($media->size) : null,
            'previewKind' => $media instanceof CmsMedia ? $media->kind->value : null,
            // A reference whose record is gone is not an error of the card:
            // the field says so and the editor picks another file.
            'missing' => $id !== null && ! $media instanceof CmsMedia,
            'kinds' => $this->kinds,
            'describable' => $this->describable,
            // What the dialog of the system offers to walk through. A rule of
            // nothing: the server decides what it keeps.
            'accept' => $this->acceptAttribute(),
            'locales' => $locales,
            'defaultLocale' => $catalog?->defaultLocale(),
            'alt' => self::dictionary($value['metadata'] ?? null, $locales),
            // What the file itself is called in words, shown as the
            // placeholder of the override: an empty override inherits it, and
            // an editor has to be able to see what they are inheriting.
            'fileAlt' => $media instanceof CmsMedia
                ? self::dictionary($media->metadata ?? [], $locales)
                : [],
            'modal' => PickerModal::NAME,
            // The dialog belongs to the document and not to the field: the
            // first field of a page draws it and the rest open it, see
            // {@see PickerModal}. Never from inside a row of a repeater: the
            // panel clones that row, and a dialog cloned per slide answers the
            // same event five times.
            'modalMarkup' => $this->getAttribute('data-level') === null ? PickerModal::once() : '',
            'endpoints' => self::endpoints(),
            // Which place of which form this is, with the numbers of the rows
            // of a repeater taken out: the dialog remembers the folder an
            // editor last took a slide picture from, and every slide is the
            // same place for that purpose.
            'scope' => self::scopeOf($this->getNameAttribute()),
        ];
    }

    /**
     * The two addresses the field itself talks to. A panel whose routes are
     * switched off gives neither, and the field then draws what it holds and
     * offers nothing.
     *
     * @return array<string, string>
     */
    private static function endpoints(): array
    {
        $routes = [];

        foreach (['picker' => 'moonshine.cms.media.picker', 'upload' => 'moonshine.cms.media.upload'] as $key => $name) {
            try {
                $routes[$key] = route($name);
            } catch (Throwable) {
                // Outside a routed installation — a schema read by a console
                // command — there is nothing to ask.
            }
        }

        return $routes;
    }

    private static function scopeOf(string $name): string
    {
        return (string) preg_replace('/\d+/', 'n', $name);
    }

    /**
     * A dictionary of `alt` by language, narrowed to the languages the catalog
     * declares: a value stored under a language an installation has since
     * dropped is not shown and not written back.
     *
     * @param  list<string>  $locales
     * @return array<string, string>
     */
    private static function dictionary(mixed $metadata, array $locales): array
    {
        $alt = is_array($metadata) ? ($metadata['alt'] ?? null) : null;
        $alt = is_array($alt) ? $alt : [];

        $dictionary = [];

        foreach ($locales as $locale) {
            $value = $alt[$locale] ?? null;
            $dictionary[$locale] = is_scalar($value) ? trim((string) $value) : '';
        }

        return $dictionary;
    }

    /**
     * A reference to a record that is gone is not an error of the card: the
     * field shows the empty state and the editor picks a new file.
     */
    private static function mediaOf(string $uuid): ?CmsMedia
    {
        try {
            return CmsMedia::query()->where('uuid', $uuid)->first();
        } catch (Throwable) {
            return null;
        }
    }

    private static function catalog(): ?LanguageCatalog
    {
        try {
            return Container::getInstance()->make(LanguageCatalog::class);
        } catch (Throwable) {
            return null;
        }
    }
}
