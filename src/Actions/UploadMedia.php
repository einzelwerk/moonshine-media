<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Actions;

use Ewk\Cms\Media\Contracts\MediaDriverContract;
use Ewk\Cms\Media\Events\MediaUploaded;
use Ewk\Cms\Media\Models\CmsMedia;
use Ewk\Cms\Media\Support\DiskRegistry;
use Ewk\Cms\Media\Support\StoragePath;
use Ewk\Cms\Media\Upload\UploadOptions;
use Ewk\Cms\Media\Upload\UploadPipeline;
use Ewk\Cms\Media\Upload\UploadResult;
use Ewk\Cms\Media\Upload\VariantQueue;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * The single way a file enters the library.
 *
 * There is one path on purpose. Two of the usual upload mistakes are about a
 * second way in: a side door that skips the checks, and an allowlist the
 * request was allowed to widen. Here the allowlist comes from the
 * configuration, the caller may only narrow it, and every route of the panel
 * and of the API ends in this method.
 *
 * The order is: extension, size, bytes, digest, the work of the kind, the
 * write, and the row last of all. The row is last because a record without a
 * file is a hole on a page, while a file without a record is a few kilobytes
 * nobody reads.
 */
final class UploadMedia
{
    public function __construct(
        private readonly MediaDriverContract $driver,
        private readonly UploadPipeline $pipeline,
        private readonly VariantQueue $variants,
        private readonly DiskRegistry $disks,
        private readonly Dispatcher $events,
    ) {
    }

    public function handle(UploadedFile $file, UploadOptions $options): UploadResult
    {
        $disk = $options->disk === null
            ? $this->disks->default()->disk
            : $this->disks->get($options->disk)->disk;

        $inspected = $this->pipeline->inspect($file, $options->allowedKinds);
        $checksum = UploadPipeline::checksum($file);

        // The same bytes are already in the library: the editor is asked
        // whether to use that record or to keep a copy all the same, and
        // nothing is written until the answer comes back as `force`.
        //
        // The search stays inside what the uploader is allowed to see. A file
        // of another owner does not exist for them, so offering it as «you
        // already have this» would hand out both a record and the knowledge
        // that somebody else holds those bytes.
        if (! $options->force && $checksum !== null) {
            $query = CmsMedia::query()->where('checksum', $checksum);

            if ($options->ownerType === null) {
                $query->whereNull('owner_type');
            } else {
                $query->where('owner_type', $options->ownerType)->where('owner_id', $options->ownerId);
            }

            $duplicate = $query->first();

            if ($duplicate instanceof CmsMedia) {
                return UploadResult::duplicate($duplicate);
            }
        }

        $uuid = (string) Str::uuid();
        $originalName = UploadPipeline::originalName($file->getClientOriginalName());
        $name = self::name($options->name, $originalName, $uuid);

        $prepared = $this->pipeline->prepare($file, $inspected);
        $stored = $this->driver->store($prepared, $disk, $uuid, 1, $name);

        try {
            $media = DB::transaction(function () use ($options, $uuid, $stored, $originalName, $prepared, $checksum, $name): CmsMedia {
                $media = CmsMedia::query()->create([
                    'uuid' => $uuid,
                    'disk' => $stored->disk,
                    'path' => $stored->path,
                    'original_name' => $originalName,
                    'mime' => $stored->mime,
                    'size' => $stored->size,
                    'width' => $stored->width,
                    'height' => $stored->height,
                    'checksum' => $checksum,
                    'name' => $name,
                    'kind' => $prepared->kind,
                    'version' => 1,
                    'folder_id' => $options->folderId,
                    'uploaded_by' => $options->uploadedBy,
                    'owner_type' => $options->ownerType,
                    'owner_id' => $options->ownerId,
                ]);

                $this->variants->create($media);

                return $media;
            });
        } catch (Throwable $exception) {
            // Nothing was recorded, so nothing may stay on the disk. The
            // whole version directory goes, because a synchronous preset may
            // already have written a conversion into it.
            $this->driver->deleteDirectory($stored->disk, StoragePath::directory($uuid, 1));

            throw $exception;
        }

        $media = $media->fresh() ?? $media;

        $this->events->dispatch(new MediaUploaded($media));

        return UploadResult::stored($media);
    }

    /**
     * The name the editor reads in the grid: the one that was asked for, else
     * the uploaded name without its extension, else the uuid, which is ugly
     * and never empty.
     */
    private static function name(?string $requested, string $originalName, string $uuid): string
    {
        $name = trim($requested ?? pathinfo($originalName, PATHINFO_FILENAME));

        return $name === '' ? $uuid : mb_substr($name, 0, 255);
    }
}
