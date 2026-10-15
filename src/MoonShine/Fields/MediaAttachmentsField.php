<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\MoonShine\Fields;

use Closure;
use Ewk\Cms\Media\Concerns\HasMediaAttachments;
use Ewk\Cms\Media\Models\CmsMedia;
use Ewk\Cms\Media\MoonShine\Fields\Concerns\NarrowsToKinds;
use Ewk\Cms\Media\MoonShine\Library\HumanSize;
use Ewk\Cms\Media\MoonShine\Library\MediaAssets;
use Ewk\Cms\Media\MoonShine\Library\MediaPreview;
use Ewk\Cms\Media\MoonShine\Library\PickerModal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use MoonShine\Contracts\Core\TypeCasts\DataWrapperContract;
use MoonShine\UI\Fields\Field;
use MoonShine\UI\Traits\Fields\CanBeMultiple;
use Throwable;

/**
 * The pictures a record owns, as one field of its form.
 *
 * It is not a column of anything. An attachment is a row of
 * `cms_media_usages` whose `field` is a role an editor would name — `cover`,
 * `gallery`, `logo` — and whose `path` is the language of that role, so there
 * is nothing on the record for the panel to write. The field therefore draws
 * what {@see HasMediaAttachments::attachedMedia()} answers and submits an
 * ordered list of uuids in hidden inputs; the write happens once the record
 * has a key, in {@see \Ewk\Cms\Media\MoonShine\Concerns\InteractsWithMediaAttachments}.
 * `apply()` is a no-op on purpose: a field that let MoonShine near the model
 * would add a `gallery` attribute to a record that has no such column.
 *
 * The inputs are named `media_attachments[<role>][<language or `_`>][]`, in
 * the order the gallery is shown, so a plain form POST carries the whole
 * state and nothing of it is kept in a session or in a draft.
 *
 * An empty field submits no uuid at all, which is indistinguishable from a
 * field that is not on the form — and those two have to mean opposite things:
 * a gallery an editor cleared is emptied, a role no form touched is left
 * alone. So every field also writes one marker,
 * `media_attachments_present[<role>][<language or `_`>]`, and the concern
 * reads the roles off the markers.
 *
 * Choosing a file is the dialog every media field of the panel opens
 * ({@see PickerModal}); «Загрузить с компьютера» goes straight up through the
 * endpoint of the library, because one path of upload means one set of rules.
 */
final class MediaAttachmentsField extends Field
{
    use CanBeMultiple;

    // What this place takes and which extensions the file dialog offers for
    // it, shared with {@see MediaPicker}, see {@see NarrowsToKinds}.
    use NarrowsToKinds;

    /** The key every attachment of every form travels under. */
    public const string REQUEST_KEY = 'media_attachments';

    /** The key that says a role was on the form at all, see the class note. */
    public const string PRESENCE_KEY = 'media_attachments_present';

    /** The place of a role that has no language of its own. */
    public const string NO_LOCALE = '_';

    protected string $view = 'moonshine-media::fields.media-attachments';

    private ?string $locale = null;

    private ?int $max = null;

    /**
     * The language of this role. `null` is the role that has none — a logo, a
     * photograph — and is a different thing from «the Russian one», never a
     * fallback for it.
     *
     * The wrapper is marked the way a translatable field marks itself, so a
     * language switch of the form — an Alpine scope around it that holds
     * `locale` — drives which of the boxes is visible. A form without such a
     * switch shows every language at once, which is what the badge on the
     * wrapper is for.
     */
    public function locale(?string $locale): static
    {
        $locale = $locale === null ? null : trim($locale);
        $this->locale = $locale === '' ? null : $locale;

        if ($this->locale !== null) {
            $this->customWrapperAttributes([
                'data-cms-locale' => $this->locale,
                // The condition names no language of its own: an attribute
                // that travels through MoonShine twice comes back escaped, and
                // `locale === &#039;ru&#039;` is not JavaScript any more. The
                // element reads the language off the mark it already carries,
                // and a form with no switch at all shows every box.
                'x-show' => 'typeof locale === typeof undefined ? true : locale === $el.dataset.cmsLocale',
            ]);
        }

        return $this;
    }

    /**
     * How many files this place holds at most. A single field holds one and
     * says so without being told; a gallery without a limit holds as many as
     * the editor drags in.
     */
    public function max(int $max): static
    {
        $this->max = $max > 0 ? $max : null;

        return $this;
    }

    /** The role the rows of `cms_media_usages` carry. */
    public function getRole(): string
    {
        return $this->getColumn();
    }

    public function getLocale(): ?string
    {
        return $this->locale;
    }

    public function getMax(): ?int
    {
        return $this->isMultiple() ? $this->max : 1;
    }

    /**
     * The name of the hidden inputs, without the `[]` the list adds: it is
     * also what a refusal of the validator is reported on, because MoonShine
     * matches an error key against `data-validation-field` with its dots
     * turned into brackets.
     */
    public function getNameAttribute(?string $index = null): string
    {
        return self::inputName($this->getRole(), $this->locale);
    }

    /**
     * `media_attachments[cover][ru]`, the name one group of uuids is
     * submitted under.
     */
    public static function inputName(string $role, ?string $locale): string
    {
        return self::REQUEST_KEY.'['.$role.']['.self::localeKey($locale).']';
    }

    /** `media_attachments.cover.ru`, the key a validation rule is written on. */
    public static function ruleKey(string $role, ?string $locale): string
    {
        return self::REQUEST_KEY.'.'.$role.'.'.self::localeKey($locale);
    }

    /** The place of a language inside the request: `_` when there is none. */
    public static function localeKey(?string $locale): string
    {
        $locale = $locale === null ? '' : trim($locale);

        return $locale === '' ? self::NO_LOCALE : $locale;
    }

    /**
     * Nothing of this field belongs to the record, see the class note: the
     * panel is kept away from the model in both directions and the rows are
     * written by the concern once the record has a key.
     */
    public function apply(Closure $default, mixed $data): mixed
    {
        return $data;
    }

    protected function resolveOnApply(): Closure
    {
        return static fn (mixed $item): mixed => $item;
    }

    /**
     * The same assets {@see MediaPicker} carries: the dialog this field opens
     * is the grid of the «Медиа» section, and a form of an entity does not
     * bring it on its own. Identical inline assets are merged by the manager,
     * so a form with both fields pays for one copy.
     *
     * @return list<\MoonShine\Contracts\AssetManager\AssetElementContract>
     */
    protected function assets(): array
    {
        return MediaAssets::inline();
    }

    /**
     * The field reads the record and never a column of it.
     *
     * @param  array<string, mixed>  $raw
     */
    protected function prepareFill(array $raw = [], ?DataWrapperContract $casted = null): mixed
    {
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function viewData(): array
    {
        $items = $this->items();

        return [
            'name' => $this->getNameAttribute(),
            'presenceName' => self::PRESENCE_KEY.'['.$this->getRole().']['.self::localeKey($this->locale).']',
            'items' => $items,
            'multiple' => $this->isMultiple(),
            'max' => $this->getMax(),
            'kinds' => $this->kinds,
            // What the dialog of the system offers to walk through. A rule of
            // nothing: the server decides what it keeps.
            'accept' => $this->acceptAttribute(),
            'modal' => PickerModal::NAME,
            // The dialog belongs to the document and not to the field: the
            // first field of a page draws it and the rest open it, see
            // {@see PickerModal}.
            'modalMarkup' => PickerModal::once(),
            'endpoints' => self::endpoints(),
            // Where the dialog opens: one memory per role and language, so a
            // gallery filled out of one folder opens in that folder again.
            'scope' => self::REQUEST_KEY.'.'.$this->getRole().'.'.self::localeKey($this->locale),
        ];
    }

    /**
     * What the record holds today, in the order it is shown.
     *
     * A form that creates a record shows nothing, because a record without a
     * key owns nothing yet. A model that does not carry
     * {@see HasMediaAttachments} is a mistake of the resource and not of the
     * editor: the field draws empty instead of throwing on the form.
     *
     * @return list<array<string, mixed>>
     */
    private function items(): array
    {
        $owner = $this->getData()?->getOriginal();

        if (! $owner instanceof Model || ! $owner->exists || ! method_exists($owner, 'attachedMedia')) {
            return [];
        }

        $attached = $owner->attachedMedia($this->getRole(), $this->locale);

        if (! $attached instanceof Collection) {
            return [];
        }

        $items = [];

        foreach ($attached as $media) {
            if ($media instanceof CmsMedia) {
                $items[] = self::item($media);
            }
        }

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    private static function item(CmsMedia $media): array
    {
        return [
            'id' => $media->uuid,
            'name' => MediaPreview::fileName($media),
            'size_human' => HumanSize::format($media->size),
            'kind' => $media->kind->value,
            'thumb_url' => MediaPreview::thumbnail($media),
        ];
    }

    /**
     * The two addresses the field talks to. A panel whose routes are switched
     * off gives neither, and the field then draws what it holds and offers
     * nothing.
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
}
