<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Actions;

use Ewk\Cms\Media\Contracts\MediaDriverContract;
use Ewk\Cms\Media\Enums\MediaVariantState;
use Ewk\Cms\Media\Models\CmsMediaVariant;
use Ewk\Cms\Media\Support\PresetRegistry;
use Throwable;

final class GenerateMediaVariant
{
    public function __construct(
        private readonly MediaDriverContract $driver,
        private readonly PresetRegistry $presets,
    ) {
    }

    public function handle(CmsMediaVariant $variant): void
    {
        $preset = $this->presets->get($variant->preset);

        try {
            $stored = $this->driver->variant($variant->cmsMedia, $preset);

            $variant->forceFill([
                'disk' => $stored->disk,
                'path' => $stored->path,
                'width' => $stored->width,
                'height' => $stored->height,
                'size' => $stored->size,
                'format' => $stored->format,
                'state' => MediaVariantState::READY,
            ])->save();
        } catch (Throwable $exception) {
            $variant->forceFill(['state' => MediaVariantState::FAILED])->save();

            throw $exception;
        }
    }
}
