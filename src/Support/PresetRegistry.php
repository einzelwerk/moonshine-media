<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Support;

use Ewk\Cms\Media\Enums\FileKind;
use InvalidArgumentException;

final class PresetRegistry
{
    /** @var array<string, Preset> */
    private array $presets;

    /**
     * The value is whatever a configuration file of the installation holds,
     * so every key is checked here and reported where the configuration is
     * read: `width` and `height` are required, the rest has a default.
     *
     * @param  array<array-key, mixed>  $config
     */
    public function __construct(array $config)
    {
        $presets = [];

        foreach ($config as $name => $preset) {
            if (! is_string($name) || ! is_array($preset)) {
                throw new InvalidArgumentException('Every entry of [cms-media.presets] must be a named array.');
            }

            $width = $preset['width'] ?? null;
            $height = $preset['height'] ?? null;

            if (! is_int($width) || ! is_int($height)) {
                throw new InvalidArgumentException("Media preset [{$name}] needs an integer [width] and [height].");
            }

            $fit = $preset['fit'] ?? FitMode::COVER->value;
            $format = $preset['format'] ?? null;
            $quality = $preset['quality'] ?? 80;
            $queued = $preset['queued'] ?? true;

            if (! is_string($fit) || ! ($format === null || is_string($format))
                || ! is_int($quality) || ! is_bool($queued)) {
                throw new InvalidArgumentException("Media preset [{$name}] has a [fit], [format], [quality] or [queued] of a wrong type.");
            }

            $presets[$name] = new Preset(
                name: $name,
                width: $width,
                height: $height,
                fit: FitMode::from($fit),
                format: $format,
                quality: $quality,
                queued: $queued,
                kinds: self::kinds($name, $preset['kinds'] ?? []),
            );
        }

        $this->presets = $presets;
    }

    public function has(string $name): bool
    {
        return isset($this->presets[$name]);
    }

    public function get(string $name): Preset
    {
        return $this->presets[$name] ?? throw new InvalidArgumentException("Unknown media preset [{$name}].");
    }

    /**
     * @return list<Preset>
     */
    public function all(): array
    {
        return array_values($this->presets);
    }

    /**
     * @return list<FileKind>
     */
    private static function kinds(string $name, mixed $kinds): array
    {
        if (! is_array($kinds)) {
            throw new InvalidArgumentException("Media preset [{$name}] needs [kinds] as a list of kinds.");
        }

        $known = [];

        foreach ($kinds as $kind) {
            $known[] = (is_string($kind) ? FileKind::tryFrom($kind) : null)
                ?? throw new InvalidArgumentException("Media preset [{$name}] names an unknown kind in [kinds].");
        }

        return $known;
    }
}
