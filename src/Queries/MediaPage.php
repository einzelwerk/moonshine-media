<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Queries;

use Ewk\Cms\Media\Models\CmsMedia;

/**
 * One page of the grid and the way to ask for the next one.
 *
 * There is no total and no page count: a cursor listing does not know them,
 * and counting a library of thousands of files on every scroll is exactly
 * what the cursor is there to avoid.
 */
final readonly class MediaPage
{
    /**
     * @param  list<CmsMedia>  $items
     */
    public function __construct(
        public array $items,
        public ?string $nextCursor = null,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }
}
