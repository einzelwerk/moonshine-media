<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Support;

/**
 * A storage the editor may choose from.
 *
 * `disk` is the name of a Laravel disk and `label` is what the editor reads:
 * the picker of a storage shows people a place, not a driver.
 */
final readonly class MediaDisk
{
    public function __construct(
        public string $disk,
        public string $label,
        public bool $default = false,
    ) {
    }
}
