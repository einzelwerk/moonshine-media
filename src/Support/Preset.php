<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Support;

use Ewk\Cms\Media\Enums\FileKind;

final readonly class Preset
{
    /**
     * @param  list<FileKind>  $kinds  the kinds this preset is made for; empty: every kind that has variants
     */
    public function __construct(
        public string $name,
        public int $width,
        public int $height,
        public FitMode $fit = FitMode::COVER,
        public ?string $format = null,
        public int $quality = 80,
        public bool $queued = true,
        public array $kinds = [],
    ) {
    }

    /**
     * Whether a record of this kind gets the preset at all: a poster is cut
     * from a video only, an avatar is made of a photograph only.
     */
    public function takes(FileKind $kind): bool
    {
        return $this->kinds === [] || in_array($kind, $this->kinds, true);
    }
}
