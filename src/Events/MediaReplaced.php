<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Events;

use Ewk\Cms\Media\Models\CmsMedia;

/**
 * The file behind a record changed. The uuid, the usages and the metadata are
 * the same ones; the version, the address and the bytes are new.
 *
 * `previousPath` is the address the record held before, already deleted from
 * the disk by the time this is raised: a listener that cached anything by
 * address needs to know which one to forget.
 */
final readonly class MediaReplaced
{
    public function __construct(
        public CmsMedia $media,
        public string $previousPath,
    ) {
    }
}
