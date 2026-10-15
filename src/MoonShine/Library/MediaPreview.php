<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\MoonShine\Library;

use Ewk\Cms\Media\Enums\FileKind;
use Ewk\Cms\Media\Models\CmsMedia;
use Throwable;

/**
 * One file as the panel shows it in a small space: the picture it draws and
 * the name it prints. The field of a picture, the field of the attachments
 * and the column of a listing ask the same two questions, and so does any
 * other screen of the panel that offers a file to pick.
 */
final class MediaPreview
{
    private function __construct()
    {
    }

    /**
     * The name an editor gave the file, then the name it was uploaded under,
     * and the uuid only for a record that has neither.
     */
    public static function fileName(CmsMedia $media): string
    {
        foreach ([$media->name, $media->original_name] as $name) {
            if (is_string($name) && trim($name) !== '') {
                return $name;
            }
        }

        return $media->uuid;
    }

    /**
     * The thumbnail the panel draws, see {@see ThumbnailPreset}: the nearest
     * ready rendition where that preset has none yet, and the original of a
     * picture where there is none at all, so a preview is never a broken
     * picture, see {@see CmsMedia::previewUrl()}. A video without its frame
     * and a document have none, and the field draws its glyph instead of
     * the address of the file in an `<img>`.
     */
    public static function thumbnail(CmsMedia $media): string
    {
        try {
            return $media->previewUrl(ThumbnailPreset::name()) ?? '';
        } catch (Throwable) {
            if (! in_array($media->kind, [FileKind::IMAGE, FileKind::VECTOR], true)) {
                return '';
            }

            try {
                return $media->url();
            } catch (Throwable) {
                return '';
            }
        }
    }
}
