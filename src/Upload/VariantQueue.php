<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Upload;

use Ewk\Cms\Media\Actions\GenerateMediaVariant;
use Ewk\Cms\Media\Contracts\VideoFramesContract;
use Ewk\Cms\Media\Enums\FileKind;
use Ewk\Cms\Media\Enums\MediaVariantState;
use Ewk\Cms\Media\Jobs\GenerateMediaVariantJob;
use Ewk\Cms\Media\Models\CmsMedia;
use Ewk\Cms\Media\Models\CmsMediaVariant;
use Ewk\Cms\Media\Support\Preset;
use Ewk\Cms\Media\Support\PresetRegistry;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Config\Repository;

/**
 * Puts the conversions of a record in line. An upload and a replacement both
 * end here, so that a new version gets exactly the variants a fresh upload
 * would.
 *
 * A raster image always has them and a vector never needs any. A video has
 * them where the installation cuts frames, see `VideoFramesContract`: the
 * frame is the picture the conversions are made of. Each preset may name the
 * kinds it is for, so a video gets a poster and no avatar.
 */
final class VariantQueue
{
    public function __construct(
        private readonly PresetRegistry $presets,
        private readonly GenerateMediaVariant $generateVariant,
        private readonly Dispatcher $bus,
        private readonly Repository $config,
        private readonly VideoFramesContract $frames,
    ) {
    }

    public function create(CmsMedia $media): void
    {
        foreach ($this->presetsOf($media) as $preset) {
            $this->generate($media, $preset);
        }
    }

    /**
     * The variants a record should have and does not: the ones of a preset
     * declared after it was uploaded, of a video uploaded before frames were
     * cut, and the ones that failed. A pending one is left to the job that
     * is already on its way.
     *
     * @return int how many were put in line
     */
    public function createMissing(CmsMedia $media): int
    {
        $existing = $media->variants()->get()->keyBy('preset');
        $count = 0;

        foreach ($this->presetsOf($media) as $preset) {
            $variant = $existing->get($preset->name);

            if ($variant instanceof CmsMediaVariant) {
                if ($variant->state !== MediaVariantState::FAILED) {
                    continue;
                }

                $variant->delete();
            }

            $this->generate($media, $preset);
            $count++;
        }

        $media->unsetRelation('variants');

        return $count;
    }

    /**
     * @return list<Preset>
     */
    private function presetsOf(CmsMedia $media): array
    {
        $makesVariants = $media->kind->hasVariants()
            || ($media->kind === FileKind::VIDEO && $this->frames->available());

        if (! $makesVariants) {
            return [];
        }

        return array_values(array_filter(
            $this->presets->all(),
            static fn (Preset $preset): bool => $preset->takes($media->kind),
        ));
    }

    private function generate(CmsMedia $media, Preset $preset): void
    {
        $variant = CmsMediaVariant::query()->create([
            'cms_media_id' => $media->id,
            'preset' => $preset->name,
            'disk' => $media->disk,
            'state' => MediaVariantState::PENDING,
        ]);

        if ($preset->queued) {
            $this->bus->dispatch((new GenerateMediaVariantJob($variant->id))->onQueue($this->queue()));
        } else {
            $this->generateVariant->handle($variant);
        }
    }

    /**
     * `null` leaves the job on the default queue of the connection.
     */
    private function queue(): ?string
    {
        $queue = $this->config->get('cms-media.queue');

        return is_string($queue) && $queue !== '' ? $queue : null;
    }
}
