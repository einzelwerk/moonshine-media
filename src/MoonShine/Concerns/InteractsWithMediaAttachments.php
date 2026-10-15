<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\MoonShine\Concerns;

use Ewk\Cms\Media\Models\CmsMedia;
use Ewk\Cms\Media\MoonShine\Fields\MediaAttachmentsField;
use Ewk\Cms\Media\Services\MediaAttachments;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use MoonShine\Contracts\Core\DependencyInjection\FieldsContract;
use MoonShine\Contracts\Core\TypeCasts\DataWrapperContract;

/**
 * What a resource needs to carry {@see MediaAttachmentsField}.
 *
 * The write is a separate step: an attachment is a row of
 * `cms_media_usages` pointing at the record and not a column of it, so it
 * cannot ride along on the apply of the panel and cannot be written before
 * the record has a key. `afterSave()` of MoonShine is called on a create and
 * on an update alike — see `ModelResource::save()` — which is the earliest
 * moment both have one.
 *
 * What is written is what the form submitted and nothing else:
 *
 * - a role the form carried is rewritten, in the order of the inputs, so
 *   dragging a gallery about and clearing it are the same one gesture as far
 *   as the storage is concerned;
 * - a role no form touched is left exactly as it was, which is what lets a
 *   card edit the Russian cover without knowing the English one exists;
 * - a uuid of a file that is no longer in the library is dropped instead of
 *   failing the save: a reference that is gone is not a mistake the editor
 *   made on this form;
 * - and nothing the form does not declare: the roles, the languages and the
 *   kinds are read off the fields MoonShine has just applied, so a request
 *   built by hand cannot name a role that is on no form, nor put a document
 *   where the field takes a picture.
 *
 * «The form carried it» is read off `media_attachments_present`, because an
 * empty gallery submits no uuid at all and would otherwise be
 * indistinguishable from a role that is not on the screen, see
 * {@see MediaAttachmentsField}.
 *
 * The service is resolved when it is needed and never injected into a
 * constructor: a resource of MoonShine is built by the panel, and a container
 * captured in a constructor is the wrong request under Octane.
 *
 * @phpstan-require-extends \MoonShine\Laravel\Resources\ModelResource
 *
 * @phpstan-ignore trait.unused (used by the resources of the installation, not by this package)
 */
trait InteractsWithMediaAttachments
{
    /**
     * Rules for one place of the form. `rules()` lives on the form page of
     * MoonShine and the write lives here, so a page asks its own resource:
     * `$this->getResource()->mediaAttachmentRules('cover', 'ru')`.
     *
     * `$required` asks for at least one file, `$max` is the same limit the
     * field draws: the browser stops at it and the server says so, because a
     * limit only the browser knows is not a limit.
     *
     * @return array<string, list<string>>
     */
    public function mediaAttachmentRules(
        string $role,
        ?string $locale = null,
        bool $required = false,
        ?int $max = null,
    ): array {
        $key = MediaAttachmentsField::ruleKey($role, $locale);
        $rules = [$required ? 'required' : 'nullable', 'array'];

        if ($max !== null && $max > 0) {
            $rules[] = 'max:'.$max;
        }

        return [
            $key => $rules,
            // The uuid of a file and never its number: the panel writes what
            // the library hands out, and a numeric key here would be a row of
            // somebody else's table.
            $key.'.*' => ['string', 'uuid'],
        ];
    }

    /**
     * The rows of every role the form carried. Public so that a resource
     * whose `save()` replaces the one of MoonShine calls it itself.
     *
     * `$fields` are the fields of that very form, which is what MoonShine
     * hands to `afterSave()`. They are the only trustworthy description of
     * what the form holds: the request is a list of roles and uuids somebody
     * typed, and a request built by hand can name a role no form has and a
     * document where a picture belongs. Given them, a group no field declares
     * is not written and a file of a kind the field does not take is dropped,
     * the way an unknown uuid is. A caller that passes nothing writes what the
     * request carried, and has to bring its own rules.
     *
     * @param  array<array-key, mixed>  $input  the submitted form
     * @param  iterable<array-key, mixed>|null  $fields  the fields of that form
     */
    public function saveMediaAttachments(Model $owner, array $input, ?iterable $fields = null): void
    {
        $present = $input[MediaAttachmentsField::PRESENCE_KEY] ?? null;
        $submitted = $input[MediaAttachmentsField::REQUEST_KEY] ?? null;

        if (! is_array($present)) {
            return;
        }

        $declared = $fields === null ? null : self::declarations($fields);
        $submitted = is_array($submitted) ? $submitted : [];
        $attachments = Container::getInstance()->make(MediaAttachments::class);

        foreach ($present as $role => $locales) {
            if (! is_string($role) || $role === '' || ! is_array($locales)) {
                continue;
            }

            foreach (array_keys($locales) as $localeKey) {
                $localeKey = (string) $localeKey;

                if ($declared !== null && ! isset($declared[$role][$localeKey])) {
                    continue;
                }

                $group = $submitted[$role][$localeKey] ?? [];

                $attachments->replace(
                    $owner,
                    $role,
                    $localeKey === MediaAttachmentsField::NO_LOCALE ? null : $localeKey,
                    self::identifiers(is_array($group) ? $group : [], $declared[$role][$localeKey] ?? null),
                );
            }
        }
    }

    /**
     * @param  DataWrapperContract<Model>  $item
     * @return DataWrapperContract<Model>
     */
    protected function afterSave(DataWrapperContract $item, FieldsContract $fields): DataWrapperContract
    {
        $item = parent::afterSave($item, $fields);

        $this->saveMediaAttachments($item->getOriginal(), request()->all(), $fields);

        return $item;
    }

    /**
     * What the form declares: every role and language it carries, and the
     * kinds the field of that place takes.
     *
     * MoonShine hands `afterSave()` the fields it has just applied, already
     * flattened out of the boxes and the tabs; a collection given by hand is
     * flattened here so that a field inside a box is not missed.
     *
     * @param  iterable<array-key, mixed>  $fields
     * @return array<string, array<string, list<string>>>
     */
    private static function declarations(iterable $fields): array
    {
        if ($fields instanceof FieldsContract) {
            $fields = $fields->onlyFields();
        }

        $declared = [];

        foreach ($fields as $field) {
            if ($field instanceof MediaAttachmentsField) {
                $declared[$field->getRole()][MediaAttachmentsField::localeKey($field->getLocale())] = $field->getKinds();
            }
        }

        return $declared;
    }

    /**
     * The uuids of the form as the keys of the library, in the order they were
     * submitted. One query and not one per file, and a uuid the library does
     * not know is simply not there.
     *
     * A file whose kind this place does not take is not there either: the
     * dialog shows one kind of file and the upload refuses another with 415,
     * but neither of those is a guard, because both of them run in a browser.
     *
     * @param  array<array-key, mixed>  $uuids
     * @param  list<string>|null  $kinds  the kinds this place takes, all of them when null
     * @return list<int>
     */
    private static function identifiers(array $uuids, ?array $kinds = null): array
    {
        $wanted = [];

        foreach ($uuids as $uuid) {
            if (is_string($uuid) && trim($uuid) !== '' && ! in_array(trim($uuid), $wanted, true)) {
                $wanted[] = trim($uuid);
            }
        }

        if ($wanted === []) {
            return [];
        }

        /** @var array<string, int> $found */
        $found = CmsMedia::query()
            ->whereIn('uuid', $wanted)
            ->when(
                $kinds !== null && $kinds !== [],
                static fn (Builder $query): Builder => $query->whereIn('kind', $kinds ?? []),
            )
            ->pluck('id', 'uuid')
            ->all();

        $ids = [];

        foreach ($wanted as $uuid) {
            if (isset($found[$uuid])) {
                $ids[] = (int) $found[$uuid];
            }
        }

        return $ids;
    }
}
