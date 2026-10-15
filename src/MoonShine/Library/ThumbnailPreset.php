<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\MoonShine\Library;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;

/**
 * Which conversion the panel draws as a thumbnail, see
 * `moonshine-media.thumbnail_preset`.
 *
 * The grid of the library, the card of a media field and the column of a
 * listing all ask the same question, and they have to get the same answer:
 * three places reading three preset names would mean three different
 * pictures of one file on one screen.
 *
 * The name is read and not cached: a preset is a matter of the installation,
 * and a test that swaps the configuration must not be answered from a value
 * frozen by the request before it.
 */
final readonly class ThumbnailPreset
{
    /**
     * The preset an installation is expected to declare; an application that
     * shapes `thumb` for its frontend names a square one of its own.
     */
    public const string DEFAULT = 'thumb';

    public static function name(): string
    {
        $container = Container::getInstance();

        if (! $container->bound(Repository::class)) {
            return self::DEFAULT;
        }

        $preset = $container->make(Repository::class)->get('moonshine-media.thumbnail_preset', self::DEFAULT);
        $preset = is_string($preset) ? trim($preset) : '';

        return $preset === '' ? self::DEFAULT : $preset;
    }
}
