<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Upload;

use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\ImageManager;

/**
 * Hands out the Intervention manager the installation asked for.
 *
 * It exists so that nothing which decodes a picture has to read the
 * configuration itself: a test binds this factory, or sets the key, and both
 * the driver and the processor get the same backend.
 *
 * `cms-media.image.driver` is where the backend is declared now;
 * `image_driver` is read after it so that an installation which published the
 * configuration before the library existed keeps working.
 */
final class ImageManagerFactory
{
    public function make(): ImageManager
    {
        return new ImageManager($this->isImagick() ? new ImagickDriver() : new GdDriver());
    }

    public function isImagick(): bool
    {
        $driver = config('cms-media.image.driver') ?? config('cms-media.image_driver', 'gd');

        return $driver === 'imagick';
    }

    /**
     * Whether the backend in use can write the format at all.
     *
     * Only AVIF is ever in question, and only on GD: a PHP build without it
     * cannot produce one, and the upload says so in words instead of letting
     * the encoder fail. An ImageMagick build is left to answer for itself,
     * because a missing delegate there surfaces as a picture that could not
     * be read, which is the other refusal of the same step.
     */
    public function supports(string $extension): bool
    {
        return $extension !== 'avif' || $this->isImagick() || function_exists('imageavif');
    }
}
