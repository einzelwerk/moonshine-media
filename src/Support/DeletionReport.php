<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Support;

/**
 * What came of deleting a selection: «удалено N, используются M».
 *
 * A batch is not a transaction. One file that is still on a page must not keep
 * the other nine from going, so every refusal lands in a list of its own and
 * the loop carries on; the panel turns the three lists into one sentence.
 */
final readonly class DeletionReport
{
    /**
     * @param  list<string>  $deleted  uuids of the records that are gone
     * @param  list<string>  $inUse  uuids still referenced by content
     * @param  list<string>  $forbidden  uuids the actor may not touch
     */
    public function __construct(
        public array $deleted = [],
        public array $inUse = [],
        public array $forbidden = [],
    ) {
    }

    public function total(): int
    {
        return count($this->deleted) + count($this->inUse) + count($this->forbidden);
    }

    public function isClean(): bool
    {
        return $this->inUse === [] && $this->forbidden === [];
    }
}
