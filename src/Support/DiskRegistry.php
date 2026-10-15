<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Support;

use InvalidArgumentException;

/**
 * The storages the library writes to, in the order the editor sees them.
 *
 * The list comes from the configuration and never from a request: a disk name
 * arriving from the browser is a way to write outside the library, so the
 * upload resolves whatever it was given through `get()` and refuses the rest.
 */
final class DiskRegistry
{
    /** @var array<string, MediaDisk> */
    private array $disks;

    private MediaDisk $default;

    /**
     * The shape is `['disk' => string, 'label' => string, 'default' => bool]`,
     * but the value is whatever a configuration file of the installation
     * happens to hold, so it is checked here and not promised in a docblock.
     *
     * @param  array<array-key, mixed>  $config
     */
    public function __construct(array $config)
    {
        $names = [];

        foreach ($config as $entry) {
            $name = is_array($entry) ? ($entry['disk'] ?? '') : '';

            if (! is_array($entry) || ! is_string($name) || $name === '') {
                throw new InvalidArgumentException('Every entry of [cms-media.disks] must name a disk.');
            }

            $names[$name] = $entry;
        }

        if ($names === []) {
            throw new InvalidArgumentException('At least one disk has to be configured in [cms-media.disks].');
        }

        // A list with nothing marked has a default all the same: the first
        // entry. Otherwise a forgotten flag would break every upload instead
        // of quietly picking the storage the installation listed first.
        $defaultName = array_key_first($names);

        foreach ($names as $name => $entry) {
            if (($entry['default'] ?? false) === true) {
                $defaultName = $name;

                break;
            }
        }

        $disks = [];

        foreach ($names as $name => $entry) {
            $label = $entry['label'] ?? '';

            $disks[$name] = new MediaDisk(
                disk: $name,
                label: is_string($label) && $label !== '' ? $label : $name,
                default: $name === $defaultName,
            );
        }

        $this->disks = $disks;
        $this->default = $disks[$defaultName];
    }

    /**
     * @return list<MediaDisk>
     */
    public function all(): array
    {
        return array_values($this->disks);
    }

    public function default(): MediaDisk
    {
        return $this->default;
    }

    public function get(string $disk): MediaDisk
    {
        return $this->disks[$disk] ?? throw new InvalidArgumentException("Unknown media disk [{$disk}].");
    }

    public function has(string $disk): bool
    {
        return isset($this->disks[$disk]);
    }

    /**
     * The panel hides the storage field entirely while there is nothing to
     * choose between.
     */
    public function isSingle(): bool
    {
        return count($this->disks) === 1;
    }
}
