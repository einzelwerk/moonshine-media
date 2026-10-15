<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Upload;

use Ewk\Cms\Media\Enums\FileKind;

/**
 * What the checks of `UploadPipeline` established about a file before anything
 * was done with its bytes: which kind it belongs to, what `finfo` read out of
 * it and which extension it is going to be stored under.
 *
 * The extension is the one the server decided on and not the one the client
 * name carried, see `UploadPipeline::storedExtension()`.
 */
final readonly class InspectedUpload
{
    public function __construct(
        public FileKind $kind,
        public string $mime,
        public string $extension,
        public int $size,
    ) {
    }
}
