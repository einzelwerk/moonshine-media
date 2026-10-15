<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Jobs;

use Ewk\Cms\Media\Actions\GenerateMediaVariant;
use Ewk\Cms\Media\Models\CmsMediaVariant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Generation of one derivative, off the request.
 *
 * There is deliberately no `Dispatchable` here: that trait lives in
 * `Illuminate\Foundation`, which Laravel publishes only as part of
 * `laravel/framework` and not as a component of its own, so a library that
 * used it could not declare what it depends on. Whoever creates the job sends
 * it through the `Illuminate\Contracts\Bus\Dispatcher` contract instead —
 * `illuminate/bus` is a component, and a contract is what a package should be
 * asking the container for anyway.
 *
 * Held until the transaction that wrote the variant row commits: the upload
 * and the replacement both queue it from inside one, and a worker that picked
 * it up earlier would find no row, or a row that was rolled back.
 */
final class GenerateMediaVariantJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(private readonly int $variantId)
    {
        $this->afterCommit();
    }

    public function handle(GenerateMediaVariant $action): void
    {
        $variant = CmsMediaVariant::query()->find($this->variantId);

        if ($variant instanceof CmsMediaVariant) {
            $action->handle($variant);
        }
    }
}
