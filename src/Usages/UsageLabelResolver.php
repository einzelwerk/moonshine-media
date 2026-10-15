<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Usages;

use Ewk\Cms\Media\Models\CmsMediaUsage;

/**
 * Names the owner of one usage. Implementations live in the packages that own
 * the record: this one knows nothing about pages, headers or metadata, and a
 * package that brings another kind of owner adds a resolver and not a branch
 * here.
 */
interface UsageLabelResolver
{
    public function supports(string $usableType): bool;

    /**
     * `null` when the record behind the usage is gone or is not the one this
     * resolver expected: the registry then falls back rather than failing the
     * whole listing.
     */
    public function resolve(CmsMediaUsage $usage): ?UsageOwnerLabel;
}
