<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Services;

use Ewk\Cms\Media\Models\CmsMediaUsage;
use Illuminate\Support\Facades\DB;

final class MediaUsageRegistrar
{
    /**
     * Replace every usage row for a given owner + field in one transaction.
     *
     * Nothing to write and nothing written before is the common case — a card
     * without a picture, a block of text — and it costs one indexed read
     * instead of a transaction and a delete on every single save.
     *
     * References of a block only: the pictures a record owns are written by
     * {@see MediaAttachments} into the same table with a position, and a
     * column that reads like one of their roles must not take them along.
     *
     * @param  array<string, int>  $mediaIdsByPath  dotted content path => media id, e.g. ['items.0.image' => 5]
     */
    public function sync(string $usableType, int|string $usableId, string $field, array $mediaIdsByPath): void
    {
        if ($mediaIdsByPath === [] && ! $this->has($usableType, $usableId, $field)) {
            return;
        }

        DB::transaction(function () use ($usableType, $usableId, $field, $mediaIdsByPath): void {
            CmsMediaUsage::query()
                ->where('usable_type', $usableType)
                ->where('usable_id', $usableId)
                ->where('field', $field)
                ->whereNull('position')
                ->delete();

            foreach ($mediaIdsByPath as $path => $mediaId) {
                CmsMediaUsage::query()->create([
                    'cms_media_id' => $mediaId,
                    'usable_type' => $usableType,
                    'usable_id' => $usableId,
                    'field' => $field,
                    'path' => $path,
                ]);
            }
        });
    }

    public function release(string $usableType, int|string $usableId): void
    {
        CmsMediaUsage::query()
            ->where('usable_type', $usableType)
            ->where('usable_id', $usableId)
            ->delete();
    }

    private function has(string $usableType, int|string $usableId, string $field): bool
    {
        return CmsMediaUsage::query()
            ->where('usable_type', $usableType)
            ->where('usable_id', $usableId)
            ->where('field', $field)
            ->whereNull('position')
            ->exists();
    }
}
