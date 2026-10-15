<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Contracts;

use Ewk\Cms\Media\Models\CmsMedia;
use Ewk\Cms\Media\Support\Preset;
use Ewk\Cms\Media\Support\StoredFile;
use Ewk\Cms\Media\Upload\PreparedFile;

/**
 * Where bytes land. Everything that writes or removes a file of the library
 * goes through here, so that no action has to know a storage.
 */
interface MediaDriverContract
{
    /**
     * Write an original that the pipeline already prepared: the driver does
     * not decode, resize or clean anything, it only decides the address and
     * the headers the file is served with.
     *
     * `$name` is what a browser should call the file when it downloads it;
     * the address on the disk never contains it, see `StoragePath`.
     */
    public function store(PreparedFile $file, string $disk, string $uuid, int $version, ?string $name = null): StoredFile;

    public function variant(CmsMedia $media, Preset $preset): StoredFile;

    /**
     * Remove one file. Idempotent: a path that is not there is not an error,
     * because the callers of this method are cleaning up after a record that
     * is already gone.
     *
     * `DeleteMedia` and `ReplaceMedia` call it with the paths the rows held,
     * never with a path built again from a uuid: a row written before the
     * versioned layout has no `v1` in it, and rebuilding the address would
     * leave its file behind.
     */
    public function delete(string $disk, string $path): void;

    /**
     * Remove a whole directory, used to clean up a version that failed
     * halfway through, see `StoragePath::directory()`. Idempotent as well.
     */
    public function deleteDirectory(string $disk, string $directory): void;
}
