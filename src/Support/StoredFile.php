<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Support;

final readonly class StoredFile
{
    public function __construct(
        public string $disk,
        public string $path,
        public string $mime,
        public int $size,
        public ?int $width,
        public ?int $height,
        public ?string $format = null,
    ) {
    }
}
