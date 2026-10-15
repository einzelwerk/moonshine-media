<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Upload;

use Ewk\Cms\Media\Models\CmsMedia;

/**
 * What came of an upload: a new record, or the record that already holds the
 * same bytes.
 *
 * A duplicate is not an error. The editor is asked whether to use the file
 * that is already in the library or to keep a copy all the same, so both
 * answers come back through one type and exactly one of the two properties is
 * filled.
 */
final readonly class UploadResult
{
    private function __construct(
        public ?CmsMedia $media,
        public ?CmsMedia $duplicate,
    ) {
    }

    public static function stored(CmsMedia $media): self
    {
        return new self(media: $media, duplicate: null);
    }

    public static function duplicate(CmsMedia $existing): self
    {
        return new self(media: null, duplicate: $existing);
    }

    public function isDuplicate(): bool
    {
        return $this->duplicate instanceof CmsMedia;
    }
}
