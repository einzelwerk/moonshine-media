<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\MoonShine\Fields;

use Closure;
use Ewk\Cms\Media\Models\CmsMedia;
use Ewk\Cms\Media\MoonShine\Library\MediaPreview;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use MoonShine\AssetManager\InlineCss;
use MoonShine\UI\Fields\Preview;

/**
 * One column of a listing that carries the picture of a record and its title.
 *
 * A wide table loses a column instead of scrolling sideways: the thumbnail
 * sits left of the title inside one cell. What it draws is an attachment of
 * the record — a row of `cms_media_usages` — and never a column of it.
 *
 * The language may be decided when the row is drawn (`fn () =>
 * app()->getLocale()`), because a listing shows the picture of the language
 * the panel is being read in and that is not known when the page is built.
 *
 * A listing that loads `mediaAttachments` with the scope of the trait costs
 * four queries whatever the number of rows; the getters read the loaded
 * relation and never ask again, so forgetting the scope is the only way back
 * to a query per row.
 */
final class AttachmentPreview extends Preview
{
    private string $role;

    /** @var string|Closure(): ?string|null */
    private string|Closure|null $locale;

    private ?string $titleColumn = null;

    /**
     * @param  string|Closure(): ?string|null  $locale
     */
    public function __construct(
        Closure|string|null $label = null,
        ?string $column = null,
        string|Closure|null $locale = null,
    ) {
        parent::__construct($label, $column);

        $this->role = trim((string) $column);
        $this->locale = $locale;
    }

    /**
     * The column the title is read from. A dictionary of languages is read in
     * the language of the panel, the way a listing reads any translated name.
     */
    public function withTitle(string $titleColumn): static
    {
        $this->titleColumn = $titleColumn;

        return $this;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    /**
     * Its own few lines and not the stylesheet of the library: a listing has
     * no media field on it and should not carry one to draw a thumbnail.
     *
     * @return list<\MoonShine\Contracts\AssetManager\AssetElementContract>
     */
    protected function assets(): array
    {
        $css = file_get_contents(__DIR__.'/../../../resources/css/cms-attachment-preview.css');

        return $css === false ? [] : [InlineCss::make($css)];
    }

    protected function resolvePreview(): Renderable
    {
        $item = $this->getData()?->getOriginal();

        /** @var view-string $view */
        $view = 'moonshine-media::fields.attachment-preview';

        return view($view, [
            'thumbnail' => $item instanceof Model ? $this->thumbnail($item) : null,
            'title' => $this->title($item),
        ]);
    }

    private function thumbnail(Model $item): ?string
    {
        if ($this->role === '' || ! method_exists($item, 'firstAttachedMedia')) {
            return null;
        }

        $media = $item->firstAttachedMedia($this->role, $this->resolvedLocale());

        return $media instanceof CmsMedia ? MediaPreview::thumbnail($media) : null;
    }

    private function resolvedLocale(): ?string
    {
        $locale = $this->locale instanceof Closure ? ($this->locale)() : $this->locale;
        $locale = is_string($locale) ? trim($locale) : '';

        return $locale === '' ? null : $locale;
    }

    private function title(mixed $item): string
    {
        if ($this->titleColumn !== null && $item instanceof Model) {
            return self::text($item->getAttribute($this->titleColumn));
        }

        return self::text($this->toValue());
    }

    /**
     * A name stored as a dictionary of languages is read in the language of
     * the panel, and falls back to whatever the record actually has: a listing
     * with empty cells is worse than one showing a name in the wrong language.
     */
    private static function text(mixed $value): string
    {
        if ($value instanceof Collection) {
            $value = $value->all();
        }

        if (is_array($value)) {
            $locale = app()->getLocale();
            $translation = $value[$locale] ?? null;

            if (is_scalar($translation) && trim((string) $translation) !== '') {
                return (string) $translation;
            }

            foreach ($value as $candidate) {
                if (is_scalar($candidate) && trim((string) $candidate) !== '') {
                    return (string) $candidate;
                }
            }

            return '';
        }

        return is_scalar($value) ? (string) $value : '';
    }
}
