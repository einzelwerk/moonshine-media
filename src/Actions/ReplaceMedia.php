<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Actions;

use Ewk\Cms\Media\Contracts\MediaDriverContract;
use Ewk\Cms\Media\Enums\FileKind;
use Ewk\Cms\Media\Events\MediaReplaced;
use Ewk\Cms\Media\Events\MediaUploaded;
use Ewk\Cms\Media\Exceptions\ForbiddenMediaActionException;
use Ewk\Cms\Media\Exceptions\UnsupportedFileException;
use Ewk\Cms\Media\Models\CmsMedia;
use Ewk\Cms\Media\Support\Actor;
use Ewk\Cms\Media\Support\StoragePath;
use Ewk\Cms\Media\Upload\InspectedUpload;
use Ewk\Cms\Media\Upload\UploadPipeline;
use Ewk\Cms\Media\Upload\VariantQueue;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * New bytes behind an existing record.
 *
 * What stays is everything a page refers to: the uuid, the name, the alt and
 * the rest of the metadata, the folder, the owner and every usage. What
 * changes is the file, and with it the version, which is part of the address:
 * the old address was handed out as immutable and kept for a year, so the new
 * file gets an address of its own instead of arguing with a cache.
 *
 * The kind cannot change. A block that draws a picture would be left holding
 * a PDF, and no page would say so until someone opened it.
 */
final class ReplaceMedia
{
    public function __construct(
        private readonly MediaDriverContract $driver,
        private readonly UploadPipeline $pipeline,
        private readonly VariantQueue $variants,
        private readonly Dispatcher $events,
    ) {
    }

    public function handle(CmsMedia $media, UploadedFile $file, Actor $actor): CmsMedia
    {
        if (! $actor->canManage($media)) {
            throw ForbiddenMediaActionException::forMedia($media);
        }

        $inspected = $this->inspect($file, $media->kind);
        $prepared = $this->pipeline->prepare($file, $inspected);

        $previousPath = $media->path;
        $previous = [];
        $version = null;

        try {
            // The version is read and raised under a lock, and the file is
            // written with the number that came out of it. Two editors who
            // replace the same file within the same second would otherwise
            // both compute «two» and the second one would write over the
            // first at an address that was promised to be immutable.
            DB::transaction(function () use ($media, $prepared, $file, &$version, &$previous, &$previousPath): void {
                $locked = CmsMedia::query()->whereKey($media->getKey())->lockForUpdate()->firstOrFail();

                $version = $locked->version + 1;
                $previousPath = $locked->path;
                $previous = self::previousFiles($locked);

                $stored = $this->driver->store($prepared, $locked->disk, $locked->uuid, $version, $locked->name);

                $media->forceFill([
                    'path' => $stored->path,
                    'mime' => $stored->mime,
                    'size' => $stored->size,
                    'width' => $stored->width,
                    'height' => $stored->height,
                    'checksum' => UploadPipeline::checksum($file),
                    'original_name' => UploadPipeline::originalName($file->getClientOriginalName()),
                    'version' => $version,
                ])->save();

                // The conversions describe the file that is gone, so the rows
                // go with it and the presets are queued again for the new one.
                $media->variants()->delete();
                $media->unsetRelation('variants');

                $this->variants->create($media);
            });
        } catch (Throwable $exception) {
            if ($version !== null) {
                $this->driver->deleteDirectory($media->disk, StoragePath::directory($media->uuid, $version));
            }

            throw $exception;
        }

        // Only now, with the record pointing at the new version: a failure a
        // moment ago would have left the record on files that still exist.
        foreach ($previous as [$disk, $path]) {
            $this->driver->delete($disk, $path);
        }

        $media = $media->fresh() ?? $media;

        $this->events->dispatch(new MediaUploaded($media));
        $this->events->dispatch(new MediaReplaced($media, $previousPath));

        return $media;
    }

    /**
     * The same checks an upload runs, narrowed to the kind of the record. A
     * file the library would take, only of another kind, is told that in one
     * sentence instead of being shown the whole list of formats.
     */
    private function inspect(UploadedFile $file, FileKind $kind): InspectedUpload
    {
        try {
            return $this->pipeline->inspect($file, [$kind]);
        } catch (UnsupportedFileException $exception) {
            throw $exception->refusedKind instanceof FileKind
                ? UnsupportedFileException::forReplacement()
                : $exception;
        }
    }

    /**
     * The addresses of the version that is being left behind, read before the
     * rows are deleted and as the rows hold them: a variant may sit on
     * another disk, and a record older than the versioned layout has a path
     * that cannot be built again from its uuid.
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function previousFiles(CmsMedia $media): array
    {
        $files = [[$media->disk, $media->path]];

        foreach ($media->variants()->get() as $variant) {
            if (is_string($variant->path) && $variant->path !== '') {
                $files[] = [$variant->disk, $variant->path];
            }
        }

        return $files;
    }
}
