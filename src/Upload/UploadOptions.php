<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Upload;

use Ewk\Cms\Media\Enums\FileKind;

/**
 * Everything the caller of `UploadMedia` decides.
 *
 * None of it widens what the library accepts: `allowedKinds` can only narrow
 * the allowlist of the configuration (a block that takes pictures alone says
 * so here), and `disk` is resolved through `DiskRegistry`, so a name that
 * arrived from a browser is an error and not a new storage.
 */
final readonly class UploadOptions
{
    /**
     * @param  string|null  $disk  null: the storage marked default
     * @param  list<FileKind>|null  $allowedKinds  null: every kind the configuration describes
     * @param  bool  $force  store a second copy although the bytes are already in the library
     * @param  string|null  $name  null: the uploaded name without its extension
     */
    public function __construct(
        public ?string $disk = null,
        public ?int $folderId = null,
        public ?int $uploadedBy = null,
        public ?string $ownerType = null,
        public int|string|null $ownerId = null,
        public ?array $allowedKinds = null,
        public bool $force = false,
        public ?string $name = null,
    ) {
    }
}
