<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Usages;

use Ewk\Cms\Media\Models\CmsMediaUsage;
use Illuminate\Database\Eloquent\Model;

/**
 * Where the packages that own records say what their records are called.
 *
 * A singleton bound by the provider of this package; the others add to it
 * with `$app->extend()` in their own providers, so no order of registration
 * can lose one.
 */
final class UsageLabelRegistry
{
    /**
     * An owner of an owner is resolved through this registry again: a block
     * names the page it sits on, and metadata names the record it describes.
     * The depth is what keeps a row that points at itself from hanging the
     * request that reads it.
     */
    private const int MAX_DEPTH = 4;

    /** @var list<UsageLabelResolver> */
    private array $resolvers = [];

    private int $depth = 0;

    /**
     * Answers already given inside one {@see self::remember()}, keyed by the
     * record that was asked about. Null while no listing is being built, so
     * that a singleton never holds a name a later request would read.
     *
     * @var array<string, UsageOwnerLabel>|null
     */
    private ?array $memo = null;

    public function register(UsageLabelResolver $resolver): void
    {
        $this->resolvers[] = $resolver;
    }

    /**
     * Resolve inside one listing, asking about each record only once.
     *
     * Two hundred usages of one picture across three pages is three questions
     * and not two hundred, and the same page named by ten different blocks is
     * one query and not ten. The memo lives for the call and no longer: a name
     * cached beyond it would outlive the row it came from, which under Octane
     * means for the life of the worker.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $work
     * @return TReturn
     */
    public function remember(callable $work): mixed
    {
        if ($this->memo !== null) {
            // Already inside one: share it instead of starting a second.
            return $work();
        }

        $this->memo = [];

        try {
            return $work();
        } finally {
            $this->memo = null;
        }
    }

    public function resolve(CmsMediaUsage $usage): UsageOwnerLabel
    {
        $usableType = $usage->usable_type;
        $usableId = $usage->usable_id;
        $key = $usableType.'#'.$usableId;

        if ($this->memo !== null && isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        $label = $this->ask($usage, $usableType, $usableId);

        if ($this->memo !== null) {
            $this->memo[$key] = $label;
        }

        return $label;
    }

    /**
     * Name a record that no usage points at directly: the page a block sits
     * on, the entity a metadata row describes. The resolvers read a usage, so
     * one is made up for them; it is never saved and never leaves this call.
     *
     * `$usable` is that record when the caller already holds it, which is what
     * turns the second hop of a listing from a query into nothing at all, see
     * {@see EagerLoadsUsageOwners}.
     */
    public function for(string $usableType, int|string $usableId, ?Model $usable = null): UsageOwnerLabel
    {
        $usage = new CmsMediaUsage();
        $usage->usable_type = $usableType;
        $usage->usable_id = $usableId;

        if ($usable instanceof Model) {
            $usage->setRelation('usable', $usable);
        }

        return $this->resolve($usage);
    }

    /**
     * What has to be loaded on the record behind a usage of this kind before
     * the resolvers are asked, see {@see EagerLoadsUsageOwners}.
     *
     * @return list<string>
     */
    public function eagerLoadsFor(string $usableType): array
    {
        $relations = [];

        foreach ($this->resolvers as $resolver) {
            if (! $resolver instanceof EagerLoadsUsageOwners || ! $resolver->supports($usableType)) {
                continue;
            }

            foreach ($resolver->eagerLoads($usableType) as $relation) {
                if (! in_array($relation, $relations, true)) {
                    $relations[] = $relation;
                }
            }
        }

        return $relations;
    }

    private function ask(CmsMediaUsage $usage, string $usableType, int|string $usableId): UsageOwnerLabel
    {
        if ($this->depth >= self::MAX_DEPTH) {
            return UsageOwnerLabel::fallback($usableType, $usableId);
        }

        $this->depth++;

        try {
            foreach ($this->resolvers as $resolver) {
                if (! $resolver->supports($usableType)) {
                    continue;
                }

                $label = $resolver->resolve($usage);

                if ($label instanceof UsageOwnerLabel) {
                    return $label;
                }
            }
        } finally {
            // A counter on a singleton has to come back down on every path,
            // or the first exception would leave the registry useless for the
            // rest of a worker's life under Octane.
            $this->depth--;
        }

        return UsageOwnerLabel::fallback($usableType, $usableId);
    }
}
