<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Support;

/**
 * The only place that knows how a file is laid out on a disk.
 *
 * `{uuid[0:2]}/{uuid}/v{version}/…`: the first two characters spread the
 * directories so that no single one holds every file, the uuid is what makes
 * the address stable while folders and names are rearranged in the panel, and
 * the version is what lets a replaced file get a new address instead of
 * fighting a cache that was told the old one is immutable.
 *
 * Nothing in the path comes from the name a client sent, see the OWASP upload
 * cheat sheet: the extension is the one the server decided on.
 */
final class StoragePath
{
    private function __construct()
    {
    }

    public static function directory(string $uuid, int $version): string
    {
        return sprintf('%s/%s/v%d', substr($uuid, 0, 2), $uuid, $version);
    }

    public static function original(string $uuid, int $version, string $extension): string
    {
        return self::directory($uuid, $version).'/original.'.strtolower(ltrim($extension, '.'));
    }

    public static function variant(string $uuid, int $version, string $preset, string $format): string
    {
        return self::directory($uuid, $version).'/'.$preset.'.'.strtolower(ltrim($format, '.'));
    }
}
