<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Usages;

/**
 * One record that actually holds the reference, inside the record an editor
 * opens.
 *
 * A page is one place to go and edit, but the file may sit in three of its
 * blocks, and «which block» is what a panel needs to scroll to. So the group
 * names the owner once and keeps the rows it was built from here: the morph
 * alias and the key of the record the usage was written against, and the
 * dotted paths inside its content.
 */
final readonly class UsageSource
{
    /**
     * @param  string  $type  morph alias of the record the usage row belongs to, e.g. `cms_block`
     * @param  list<string>  $fields  dotted paths inside that record
     */
    public function __construct(
        public string $type,
        public int|string $id,
        public array $fields,
    ) {
    }
}
