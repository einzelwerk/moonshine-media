<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Events;

use Ewk\Cms\Media\Models\CmsMedia;

/**
 * New bytes are in the library. It is the point where an optimiser, a virus
 * scanner or an audit log hooks in; nothing in this package listens to it.
 *
 * A replacement raises it too: the record is not new, but the file is, and a
 * listener that works on bytes has the same work to do.
 */
final readonly class MediaUploaded
{
    public function __construct(public CmsMedia $media)
    {
    }
}
