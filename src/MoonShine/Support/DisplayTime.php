<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\MoonShine\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;

/**
 * The clock the panel shows instants on, see `moonshine-media.display_timezone`.
 *
 * The database keeps instants in the zone of the application, usually UTC,
 * while the people who read the panel live in one zone of their own. When
 * a file was uploaded, and every other instant a screen built on this package
 * prints, is read on this clock, so one screen never mixes two of them.
 *
 * Wall-clock values stored as content (a date and a time somebody typed) are
 * not instants and are not converted.
 *
 * The zone is read and not cached, for the same reason as the thumbnail
 * preset: a test that swaps the configuration must see the change.
 */
final readonly class DisplayTime
{
    public static function zone(): string
    {
        $container = Container::getInstance();

        if (! $container->bound(Repository::class)) {
            return date_default_timezone_get();
        }

        $config = $container->make(Repository::class);

        foreach ([$config->get('moonshine-media.display_timezone'), $config->get('app.timezone')] as $zone) {
            if (is_string($zone) && trim($zone) !== '') {
                return $zone;
            }
        }

        return date_default_timezone_get();
    }

    /** An instant read on the clock of the panel. */
    public static function local(DateTimeInterface $instant): CarbonImmutable
    {
        return CarbonImmutable::instance($instant)->setTimezone(self::zone());
    }
}
