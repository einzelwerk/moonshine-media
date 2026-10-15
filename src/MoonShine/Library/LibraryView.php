<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\MoonShine\Library;

use Ewk\Cms\Media\Enums\FileKind;
use Ewk\Cms\Media\Localization\LanguageCatalog;
use Ewk\Cms\Media\Queries\MediaSort;
use Ewk\Cms\Media\Support\DiskRegistry;
use Ewk\Cms\Media\Support\KindRegistry;
use Ewk\Cms\Media\Support\KindRule;

/**
 * Everything both screens of the library need before a single tile is drawn:
 * the addresses, the dictionary, the languages of the content, the kinds the
 * configuration describes and the storages an editor may choose from.
 *
 * It lives here and not in the component of the section because the picker
 * modal is the same grid in a dialog, and a second copy of these lists is how
 * the two would drift apart: a kind an installation switches off has to
 * disappear from the chips of both, and the formats a refusal names have to be
 * the same sentence in both.
 *
 * Every list takes an optional narrowing by kind. The section passes none and
 * shows the library whole; the picker passes what the field declares it
 * accepts, so «Выбрать из библиотеки» on a field for pictures offers pictures.
 */
final readonly class LibraryView
{
    public function __construct(
        private LanguageCatalog $languages,
        private KindRegistry $kinds,
        private DiskRegistry $disks,
        private FolderTree $tree,
    ) {
    }

    /**
     * The addresses of the section. The three that name one file carry
     * `__id__`, which the script replaces: a route is built here, where the
     * names of the routes live, and never glued together in the browser.
     *
     * @return array<string, string>
     */
    public function endpoints(): array
    {
        return [
            'items' => route('moonshine.cms.media.items'),
            'folders' => route('moonshine.cms.media.folders'),
            'picker' => route('moonshine.cms.media.picker'),
            'upload' => route('moonshine.cms.media.upload'),
            'move' => route('moonshine.cms.media.move'),
            'delete' => route('moonshine.cms.media.delete'),
            'show' => route('moonshine.cms.media.show', ['uuid' => '__id__']),
            'update' => route('moonshine.cms.media.update', ['uuid' => '__id__']),
            'replace' => route('moonshine.cms.media.replace', ['uuid' => '__id__']),
            'folderCreate' => route('moonshine.cms.media.folders.create'),
            'folderRename' => route('moonshine.cms.media.folders.rename', ['folder' => '__id__']),
            'folderMove' => route('moonshine.cms.media.folders.move', ['folder' => '__id__']),
            'folderDelete' => route('moonshine.cms.media.folders.delete', ['folder' => '__id__']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function labels(): array
    {
        $labels = __('moonshine-media::library');

        if (! is_array($labels)) {
            return [];
        }

        $typed = [];

        foreach ($labels as $key => $value) {
            $typed[(string) $key] = $value;
        }

        return $typed;
    }

    /**
     * @return list<string>
     */
    public function locales(): array
    {
        return $this->languages->locales();
    }

    public function defaultLocale(): string
    {
        return $this->languages->defaultLocale();
    }

    /**
     * «Все» first, then one chip per kind the configuration describes, in the
     * order it describes them. A kind an installation switched off is not
     * offered to filter by, and neither is one the field does not take.
     *
     * @param  list<FileKind>|null  $only  null: every configured kind
     * @return list<array{value: string, label: string}>
     */
    public function kindChips(?array $only = null): array
    {
        $chips = [['value' => '', 'label' => (string) __('moonshine-media::library.kinds.all')]];

        foreach ($this->rules($only) as $rule) {
            $chips[] = [
                'value' => $rule->kind->value,
                'label' => (string) __('moonshine-media::library.kinds.'.$rule->kind->value),
            ];
        }

        return $chips;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function sorts(): array
    {
        $sorts = [];

        foreach (MediaSort::cases() as $sort) {
            $sorts[] = [
                'value' => $sort->value,
                'label' => (string) __('moonshine-media::library.sorts.'.$sort->value),
            ];
        }

        return $sorts;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function diskOptions(): array
    {
        $options = [];

        foreach ($this->disks->all() as $disk) {
            $options[] = ['value' => $disk->disk, 'label' => $disk->label];
        }

        return $options;
    }

    public function isSingleDisk(): bool
    {
        return $this->disks->isSingle();
    }

    public function defaultDisk(): string
    {
        return $this->disks->default()->disk;
    }

    /**
     * @return array{root: array{count: int}, folders: list<array<string, mixed>>}
     */
    public function tree(): array
    {
        return $this->tree->build();
    }

    /**
     * The kinds the configuration describes, as a plain list of names: what a
     * picker hands back to the upload so that the server narrows the very same
     * allowlist it narrowed the grid by.
     *
     * @param  list<FileKind>|null  $only  null: every configured kind
     * @return list<string>
     */
    public function kindNames(?array $only = null): array
    {
        return array_map(
            static fn (KindRule $rule): string => $rule->kind->value,
            $this->rules($only),
        );
    }

    /**
     * What the file dialog offers to pick from. Not a rule of anything: the
     * server decides what it keeps, and this only spares the editor a walk
     * through a folder of files that would be refused.
     *
     * @param  list<FileKind>|null  $only  null: every configured kind
     */
    public function accept(?array $only = null): string
    {
        $extensions = [];

        foreach ($this->rules($only) as $rule) {
            foreach ($rule->extensions as $extension) {
                $extensions[] = '.'.$extension;
            }
        }

        return implode(',', array_unique($extensions));
    }

    /**
     * The lines under «Отпустите, чтобы загрузить»: one per kind, with the
     * formats spelled out and the limit in megabytes, straight out of the
     * allowlist, so a configuration that narrows a kind narrows this too.
     *
     * @param  list<FileKind>|null  $only  null: every configured kind
     * @return list<string>
     */
    public function uploadHints(?array $only = null): array
    {
        return array_map(
            fn (KindRule $rule): string => (string) __('moonshine-media::library.uploader.kind_line', [
                'kind' => (string) __('moonshine-media::library.kinds.'.$rule->kind->value),
                'formats' => implode(', ', array_map(mb_strtoupper(...), $rule->extensions)),
                'size' => HumanSize::limit($rule->maxSize),
            ]),
            $this->rules($only),
        );
    }

    /**
     * The rules in force here. A narrowing that names a kind the installation
     * does not describe narrows to nothing rather than widening: the
     * configuration is the allowlist and a caller can only take from it.
     *
     * @param  list<FileKind>|null  $only
     * @return list<KindRule>
     */
    private function rules(?array $only): array
    {
        $rules = $this->kinds->all();

        if ($only === null || $only === []) {
            return $rules;
        }

        return array_values(array_filter(
            $rules,
            static fn (KindRule $rule): bool => in_array($rule->kind, $only, true),
        ));
    }
}
