<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Enums;

/**
 * What a stored file is.
 *
 * The kind is decided by the server from the bytes and never taken from the
 * request; the list of extensions and MIME types of each kind lives in the
 * configuration of the package.
 *
 * No `label()` here on purpose: a caption belongs to whoever draws a screen,
 * and this package must not depend on the admin panel (see the arch test).
 */
enum FileKind: string
{
    case IMAGE = 'image';
    case VECTOR = 'vector';
    case VIDEO = 'video';
    case AUDIO = 'audio';
    case DOCUMENT = 'document';

    /**
     * Conversions are always made for a raster image: a vector scales by
     * itself, and a page of a document needs tools that are not in the image.
     * A video has them only where the installation cuts frames, which is a
     * question for `VideoFramesContract` and not for the kind.
     */
    public function hasVariants(): bool
    {
        return $this === self::IMAGE;
    }
}
