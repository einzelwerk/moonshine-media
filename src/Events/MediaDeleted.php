<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Events;

/**
 * A record of the library is gone and so are its files.
 *
 * The uuid and the disk and nothing else: by the time a listener runs there is
 * no row left to hand it, and those two are what an audit, an antivirus or a
 * cache of addresses needs to know which file went away.
 */
final readonly class MediaDeleted
{
    public function __construct(
        public string $uuid,
        public string $disk,
    ) {
    }
}
