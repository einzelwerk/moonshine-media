<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Usages;

/**
 * A resolver that has to look past the record the usage row names.
 *
 * A block is the case this exists for: the row points at the block, and the
 * name the panel shows is the page above it. Without this the second hop is
 * one query per block, which on a picture used across a site is the whole
 * listing spent on `select * from cms_pages where id = ?`.
 *
 * The relations named here are loaded on the `usable` of every supported row
 * in one go, see {@see MediaUsages}. This package never spells out a relation
 * of another package, so the resolver that knows the model names it.
 */
interface EagerLoadsUsageOwners
{
    /**
     * @return list<string> relations of the record behind a usage of this kind
     */
    public function eagerLoads(string $usableType): array;
}
