<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Usages;

use Ewk\Cms\Media\Models\CmsMedia;
use Ewk\Cms\Media\Models\CmsMediaUsage;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Where a file is used: the list an editor is shown instead of the deletion
 * they asked for.
 *
 * Grouped by the record the registry named and not by the row the usage was
 * written against. A usage of a block is written against the block, but the
 * record an editor opens is the page above it, and a picture in three blocks
 * of one page is one page and not three. Which blocks they are is kept in the
 * sources of the group, because that is what a panel scrolls to.
 *
 * The whole listing runs inside one memo of the registry, so a page named by
 * ten blocks is one query and not ten.
 */
final readonly class MediaUsages
{
    public function __construct(
        private UsageLabelRegistry $labels,
    ) {
    }

    /**
     * @return list<UsageGroup>
     */
    public function of(CmsMedia $media): array
    {
        /** @var Collection<int, CmsMediaUsage> $usages */
        $usages = $media->usages()->orderBy('id')->get();

        if ($usages->isEmpty()) {
            return [];
        }

        return $this->labels->remember(fn (): array => $this->group($usages));
    }

    /**
     * @param  Collection<int, CmsMediaUsage>  $usages
     * @return list<UsageGroup>
     */
    private function group(Collection $usages): array
    {
        $this->eagerLoadOwners($usages);

        /** @var array<string, array{owner: UsageOwnerLabel, sources: array<string, UsageSource>}> $grouped */
        $grouped = [];

        foreach ($usages as $usage) {
            $label = $this->labels->resolve($usage);
            $sourceKey = $usage->usable_type.'#'.$usage->usable_id;

            // A label that names no owner is the fallback: the row itself is
            // then the best answer there is, and it groups on its own.
            $groupKey = $label->ownerType === null
                ? $sourceKey
                : $label->ownerType.'#'.$label->ownerId;

            if (! isset($grouped[$groupKey])) {
                $grouped[$groupKey] = ['owner' => $label, 'sources' => []];
            }

            $field = self::fieldOf($usage);
            $source = $grouped[$groupKey]['sources'][$sourceKey] ?? null;
            $fields = $source instanceof UsageSource ? $source->fields : [];

            if (! in_array($field, $fields, true)) {
                $fields[] = $field;
            }

            $grouped[$groupKey]['sources'][$sourceKey] = new UsageSource(
                type: $usage->usable_type,
                id: $usage->usable_id,
                fields: $fields,
            );
        }

        uasort(
            $grouped,
            static fn (array $a, array $b): int => strnatcasecmp($a['owner']->title, $b['owner']->title),
        );

        return array_values(array_map(
            static fn (array $group): UsageGroup => new UsageGroup($group['owner'], array_values($group['sources'])),
            $grouped,
        ));
    }

    /**
     * What the place inside the record is called in the listing.
     *
     * For a block it is the dotted path inside the content, which is the
     * precise place and the only thing that tells two pictures of one block
     * apart. For an attachment the path holds a language instead, and the
     * language alone would read as «ru»; the role is what an editor would
     * name, so it reads as `cover` or `cover:ru`.
     */
    private static function fieldOf(CmsMediaUsage $usage): string
    {
        if ($usage->isAttachment()) {
            $locale = $usage->locale();

            return $locale === null ? $usage->field : $usage->field.':'.$locale;
        }

        return $usage->path !== '' ? $usage->path : $usage->field;
    }

    /**
     * The records behind the rows, in one query per kind instead of one per
     * row, and with whatever the resolvers need above them, see
     * {@see EagerLoadsUsageOwners}.
     *
     * A type nobody mapped is left out of the eager load on purpose: Eloquent
     * would take the alias for a class name and fail on the whole listing,
     * while leaving it alone only costs that one row its name.
     *
     * @param  Collection<int, CmsMediaUsage>  $usages
     */
    private function eagerLoadOwners(Collection $usages): void
    {
        $loadable = $usages->filter(
            static fn (CmsMediaUsage $usage): bool => self::isResolvableMorph($usage->usable_type)
        );

        if ($loadable->isEmpty()) {
            return;
        }

        $morphWith = $this->morphWith($loadable);

        // The callback of an eager load is handed the relation, and for a
        // polymorphic one that is a `MorphTo`, which is the only kind that
        // can be told what to load above each of its kinds.
        $loadable->load(['usable' => static function (MorphTo $relation) use ($morphWith): void {
            if ($morphWith !== []) {
                $relation->morphWith($morphWith);
            }
        }]);
    }

    /**
     * @param  Collection<int, CmsMediaUsage>  $usages
     * @return array<class-string|string, list<string>>
     */
    private function morphWith(Collection $usages): array
    {
        $morphWith = [];

        foreach ($usages->map(static fn (CmsMediaUsage $usage): string => $usage->usable_type)->unique() as $usableType) {
            $relations = $this->labels->eagerLoadsFor($usableType);

            if ($relations === []) {
                continue;
            }

            $morphWith[Relation::getMorphedModel($usableType) ?? $usableType] = $relations;
        }

        return $morphWith;
    }

    private static function isResolvableMorph(string $usableType): bool
    {
        return Relation::getMorphedModel($usableType) !== null || class_exists($usableType);
    }
}
