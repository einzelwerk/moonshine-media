<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Actions;

use Ewk\Cms\Media\Contracts\MediaDriverContract;
use Ewk\Cms\Media\Events\MediaDeleted;
use Ewk\Cms\Media\Exceptions\ForbiddenMediaActionException;
use Ewk\Cms\Media\Exceptions\MediaInUseException;
use Ewk\Cms\Media\Models\CmsMedia;
use Ewk\Cms\Media\Models\CmsMediaVariant;
use Ewk\Cms\Media\Support\Actor;
use Ewk\Cms\Media\Support\DeletionReport;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;

/**
 * Deleting a record of the library.
 *
 * Two rules decide the order of everything below. A record that is still used
 * is not deleted at all, and the row goes before the files: a failure on a
 * disk then leaves files nobody points at, which a sweep can collect, instead
 * of a row pointing at bytes that are gone, which every page showing it would
 * break on. «Before the files» means before them and after the commit, even
 * when a caller wrapped the whole thing in a transaction of its own.
 *
 * There is no bin. Deleting is final.
 */
final readonly class DeleteMedia
{
    public function __construct(
        private MediaDriverContract $driver,
        private DatabaseManager $database,
        private Dispatcher $events,
    ) {
    }

    /**
     * @throws ForbiddenMediaActionException when the actor may not manage this file
     * @throws MediaInUseException when content still points at it
     */
    public function handle(CmsMedia $media, Actor $actor): void
    {
        if (! $actor->canManage($media)) {
            throw ForbiddenMediaActionException::forMedia($media);
        }

        $usages = $media->usages()->count();

        if ($usages > 0) {
            throw MediaInUseException::forMedia($media, $usages);
        }

        $uuid = $media->uuid;
        $disk = $media->disk;
        $paths = $this->paths($media);
        $directories = $this->directories($uuid, $disk, $paths);

        $connection = $this->database->connection($media->getConnectionName());

        $connection->transaction(
            // Variants and usages are gone with the row: the foreign keys
            // cascade, so the transaction holds one statement and the files
            // are touched only once it has been committed.
            static fn () => $media->delete(),
        );

        $sweep = function () use ($paths, $directories, $uuid, $disk): void {
            foreach ($paths as $file) {
                $this->driver->delete($file['disk'], $file['path']);
            }

            foreach ($directories as $directory) {
                $this->driver->deleteDirectory($directory['disk'], $directory['directory']);
            }

            $this->events->dispatch(new MediaDeleted($uuid, $disk));
        };

        // A caller may have a transaction of its own open around this one, in
        // which case the delete above was a savepoint and nothing is committed
        // yet. Removing the files there would undo itself the moment that
        // outer transaction rolled back: the row would come back and its bytes
        // would not. So the sweep waits for the real commit, and runs straight
        // away when there is none to wait for.
        if ($connection->transactionLevel() > 0) {
            $connection->afterCommit($sweep);

            return;
        }

        $sweep();
    }

    /**
     * Deleting a selection. One refusal never stops the rest: a file that is
     * still on a page must not keep the other nine from going, so each one is
     * tried on its own and the report says what happened to all of them.
     *
     * @param  iterable<array-key, CmsMedia>  $media
     */
    public function handleMany(iterable $media, Actor $actor): DeletionReport
    {
        $deleted = [];
        $inUse = [];
        $forbidden = [];

        foreach ($media as $record) {
            try {
                $this->handle($record, $actor);
                $deleted[] = $record->uuid;
            } catch (ForbiddenMediaActionException) {
                $forbidden[] = $record->uuid;
            } catch (MediaInUseException) {
                $inUse[] = $record->uuid;
            }
        }

        return new DeletionReport($deleted, $inUse, $forbidden);
    }

    /**
     * Every byte the record accounts for, read off the rows and not rebuilt
     * from the uuid: records written before the library had versions carry a
     * path without `/v1/`, and the stored path is the only thing that is true
     * for all of them.
     *
     * @return list<array{disk: string, path: string}>
     */
    private function paths(CmsMedia $media): array
    {
        $paths = [];

        if ($media->path !== '') {
            $paths[] = ['disk' => $media->disk, 'path' => $media->path];
        }

        foreach ($media->variants()->get() as $variant) {
            /** @var CmsMediaVariant $variant */
            if ($variant->path !== null && $variant->path !== '') {
                $paths[] = ['disk' => $variant->disk, 'path' => $variant->path];
            }
        }

        return $paths;
    }

    /**
     * The folders to sweep after the files. `{uuid[0:2]}/{uuid}` belongs to
     * this record alone, so removing it takes the directories of older
     * versions that a replacement left behind, see
     * {@see \Ewk\Cms\Media\Support\StoragePath}.
     *
     * The folder of a stored path is swept too, but only while it still has
     * the uuid inside it. A path from before the library, `ab/file.jpg`, has
     * `ab` for a parent, and `ab` is shared with every other file whose uuid
     * starts the same way.
     *
     * @param  list<array{disk: string, path: string}>  $paths
     * @return list<array{disk: string, directory: string}>
     */
    private function directories(string $uuid, string $disk, array $paths): array
    {
        $own = substr($uuid, 0, 2).'/'.$uuid;

        // Seeded with the storage of the record itself, so that a row whose
        // path never got written still has its folder swept.
        $directories = [$disk.'|'.$own => ['disk' => $disk, 'directory' => $own]];

        foreach ($paths as $file) {
            $directory = dirname($file['path']);

            if ($directory !== '' && $directory !== '.' && str_contains($directory, $uuid)) {
                $directories[$file['disk'].'|'.$directory] = [
                    'disk' => $file['disk'],
                    'directory' => $directory,
                ];
            }

            $directories[$file['disk'].'|'.$own] = [
                'disk' => $file['disk'],
                'directory' => $own,
            ];
        }

        return array_values($directories);
    }
}
