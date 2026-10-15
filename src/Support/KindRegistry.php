<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Support;

use Ewk\Cms\Media\Enums\FileKind;
use InvalidArgumentException;

/**
 * The allowlist of the library: which extensions and MIME types belong to
 * which kind, and how large a file of that kind may be.
 *
 * The list is declared by the installation and is never assembled from a
 * request. The first of the four mistakes that OWASP lists for uploads is an
 * allowlist that came from the client, so nothing here takes an argument that
 * widens it.
 */
final class KindRegistry
{
    /** @var array<string, KindRule> */
    private array $rules = [];

    /** @var array<string, FileKind> */
    private array $byExtension = [];

    /** @var array<string, FileKind> */
    private array $byMime = [];

    /**
     * @param  array<array-key, mixed>  $config
     */
    public function __construct(array $config)
    {
        foreach ($config as $name => $rule) {
            $kind = FileKind::tryFrom((string) $name);

            if (! $kind instanceof FileKind) {
                throw new InvalidArgumentException("Unknown media kind [{$name}] in [cms-media.kinds].");
            }

            if (! is_array($rule)) {
                throw new InvalidArgumentException("Media kind [{$kind->value}] must be an array in [cms-media.kinds].");
            }

            $extensions = array_values(array_unique(array_map(
                static fn (string $extension): string => strtolower(ltrim($extension, '.')),
                self::strings($rule['extensions'] ?? null),
            )));

            $mimes = array_values(array_unique(array_map(
                static fn (string $mime): string => self::normalizeMime($mime),
                self::strings($rule['mimes'] ?? null),
            )));

            $maxSize = is_numeric($rule['max_size'] ?? null) ? (int) $rule['max_size'] : 0;

            // A limit of zero is not «no limit», it is a key that was left
            // out, and it would refuse every file of that kind at the third
            // step of the upload. It is a mistake in the configuration and it
            // is reported where the configuration is read.
            if ($maxSize <= 0) {
                throw new InvalidArgumentException(
                    "Media kind [{$kind->value}] needs a positive [max_size] in [cms-media.kinds]."
                );
            }

            $this->rules[$kind->value] = new KindRule(
                kind: $kind,
                extensions: $extensions,
                mimes: $mimes,
                maxSize: $maxSize,
            );

            // The first kind that claims an extension or a MIME type keeps it:
            // a file belongs to exactly one kind, and the order of the
            // configuration is what decides ties.
            foreach ($extensions as $extension) {
                $this->byExtension[$extension] ??= $kind;
            }

            foreach ($mimes as $mime) {
                $this->byMime[$mime] ??= $kind;
            }
        }
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, is_string(...)));
    }

    public function has(FileKind $kind): bool
    {
        return isset($this->rules[$kind->value]);
    }

    public function get(FileKind $kind): KindRule
    {
        return $this->rules[$kind->value]
            ?? throw new InvalidArgumentException("Media kind [{$kind->value}] is not configured in [cms-media.kinds].");
    }

    /**
     * @return list<KindRule>
     */
    public function all(): array
    {
        return array_values($this->rules);
    }

    public function kindForExtension(string $extension): ?FileKind
    {
        return $this->byExtension[strtolower(ltrim($extension, '.'))] ?? null;
    }

    public function kindForMime(string $mime): ?FileKind
    {
        return $this->byMime[self::normalizeMime($mime)] ?? null;
    }

    /**
     * `finfo` and the browser both append parameters to a MIME type
     * (`text/xml; charset=us-ascii`), and the case of the type is not
     * significant. Only the type and the subtype take part in the comparison.
     */
    public static function normalizeMime(string $mime): string
    {
        $type = strstr($mime, ';', true);

        return strtolower(trim($type === false ? $mime : $type));
    }
}
