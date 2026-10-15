<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Concerns;

use Ewk\Cms\Media\Models\CmsMedia;
use Ewk\Cms\Media\Models\CmsMediaUsage;
use Ewk\Cms\Media\Services\MediaAttachments;
use Ewk\Cms\Media\Services\MediaUsageRegistrar;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * The pictures a record owns.
 *
 * There is no table of attachments: an attachment is a row of
 * `cms_media_usages` whose `field` is a role (`cover`, `gallery`, `logo`),
 * whose `path` is a language for the roles that have one and an empty string
 * for the rest, and whose `position` is the order inside a gallery. A picture
 * of a tour is therefore a usage like any other, which is what gives the
 * catalogue «where is this file used» and the refusal to delete a file that is
 * still shown, for free.
 *
 * Reading is done here and writing in {@see MediaAttachments}: a model that
 * wrote its own rows would have every caller inventing its own order and its
 * own transaction.
 *
 * There is no fallback of languages in this trait. A role asked for in a
 * language that has nothing answers with nothing, because which language to
 * fall back to is a decision of the application and not of a library.
 *
 * @phpstan-require-extends Model
 */
trait HasMediaAttachments
{
    /**
     * A record that is gone owns nothing. `deleted` and not `deleting`, so a
     * failed delete leaves the rows where they were.
     *
     * A soft delete is not a delete: the record comes back and has to come
     * back with its pictures, so only the force delete releases. A model
     * without soft deletes has no `isForceDeleting()` and every delete of it
     * is final.
     */
    public static function bootHasMediaAttachments(): void
    {
        static::deleted(static function (Model $model): void {
            if (method_exists($model, 'isForceDeleting') && ! $model->isForceDeleting()) {
                return;
            }

            app(MediaAttachments::class)->release($model);
        });
    }

    /**
     * Every attachment of the record, ordered the way a gallery is shown.
     *
     * The morph alias of the model is what lands in `usable_type`, because
     * `MorphMany` writes `getMorphClass()`, which is the alias when one is
     * registered and the class name when it is not.
     *
     * Attachments only: a record that also holds references inside the content
     * of its blocks has rows of {@see MediaUsageRegistrar} under the same
     * owner, and those are written without a position, see
     * {@see CmsMediaUsage::isAttachment()}. They are usages all the same and
     * they belong in «where is this file used», but they are not pictures this
     * record owns and a gallery must not show them.
     *
     * @return MorphMany<CmsMediaUsage, $this>
     */
    public function mediaAttachments(): MorphMany
    {
        return $this->morphMany(CmsMediaUsage::class, 'usable')
            ->whereNotNull('position')
            ->orderBy('position')
            ->orderBy('id');
    }

    /**
     * The files of one role, in order. `null` for the language is the role
     * that has none: it is stored with an empty `path`, and it is a different
     * thing from «the Russian one», never a fallback for it.
     *
     * @return Collection<int, CmsMedia>
     */
    public function attachedMedia(string $role, ?string $locale = null): Collection
    {
        return $this->mediaOf($this->attachmentRows($role, $locale ?? ''));
    }

    public function firstAttachedMedia(string $role, ?string $locale = null): ?CmsMedia
    {
        return $this->attachedMedia($role, $locale)->first();
    }

    /**
     * One role in every language at once, for a form that edits all of them
     * on one screen. The role without a language is under the empty key.
     *
     * @return array<string, Collection<int, CmsMedia>>
     */
    public function attachedMediaByLocale(string $role): array
    {
        $byLocale = [];

        foreach ($this->attachmentRows($role, null) as $usage) {
            $byLocale[$usage->path][] = $usage;
        }

        return array_map(
            fn (array $rows): Collection => $this->mediaOf(new Collection($rows)),
            $byLocale,
        );
    }

    /**
     * Listing records with their pictures without a query per record, and
     * without the variants costing one more each.
     *
     * The getters then read what was loaded and nothing else: a role left out
     * of `$roles` reads as empty instead of going for it, so a listing names
     * every role it shows, or none at all.
     *
     * @param  Builder<static>  $query
     * @param  list<string>|null  $roles  the roles the listing draws, all of them when null
     */
    public function scopeWithAttachedMedia(Builder $query, ?array $roles = null): void
    {
        $query->with(['mediaAttachments' => static function (MorphMany $relation) use ($roles): void {
            if ($roles !== null && $roles !== []) {
                $relation->whereIn('field', $roles);
            }

            $relation->with('cmsMedia.variants');
        }]);
    }

    /**
     * The rows of one role, already ordered. `null` for the path means every
     * language of that role.
     *
     * A relation that is already loaded is read and never asked about again:
     * the point of {@see self::scopeWithAttachedMedia()} is that a listing of
     * fifty records costs four queries, and a getter that queried anyway would
     * quietly give the fifty back.
     *
     * @return Collection<int, CmsMediaUsage>
     */
    private function attachmentRows(string $role, ?string $path): Collection
    {
        if ($this->relationLoaded('mediaAttachments')) {
            /** @var Collection<int, CmsMediaUsage> $loaded */
            $loaded = $this->getRelation('mediaAttachments');

            /** @var Collection<int, CmsMediaUsage> $rows */
            $rows = $loaded
                ->filter(static fn (CmsMediaUsage $usage): bool => $usage->field === $role
                    && ($path === null || $usage->path === $path))
                ->sortBy([['position', 'asc'], ['id', 'asc']])
                ->values();

            return $rows;
        }

        // An unsaved record owns nothing yet, and asking the database about a
        // key that is null would answer with the rows of somebody else.
        if ($this->getKey() === null) {
            return new Collection();
        }

        $query = $this->mediaAttachments()->where('field', $role);

        if ($path !== null) {
            $query->where('path', $path);
        }

        return $query->with('cmsMedia.variants')->get();
    }

    /**
     * The files behind the rows. A row whose file is gone is dropped instead
     * of becoming a null in the middle of a gallery; the foreign key makes
     * that impossible in the database, but a relation that was never loaded
     * looks the same from here.
     *
     * @param  Collection<int, CmsMediaUsage>  $rows
     * @return Collection<int, CmsMedia>
     */
    private function mediaOf(Collection $rows): Collection
    {
        $media = [];

        foreach ($rows as $usage) {
            $file = $usage->cmsMedia;

            if ($file instanceof CmsMedia) {
                $media[] = $file;
            }
        }

        return new Collection($media);
    }
}
