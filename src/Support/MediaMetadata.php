<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Support;

use Ewk\Cms\Media\Exceptions\InvalidMediaLocaleException;

/**
 * The part of `cms_media.metadata` an editor fills in: the default `alt` and
 * `caption` as dictionaries of languages, and one shared `title`.
 *
 * `alt` is translatable always, whatever the field that holds the file
 * declares: the caption is the single property a visitor reads, and one in
 * the wrong language is a defect and not a fallback.
 *
 * An empty string is dropped rather than stored, so that «not filled in» and
 * «filled in with nothing» do not both have to be handled by every reader.
 */
final readonly class MediaMetadata
{
    /**
     * @param  array<string, string>  $alt  language code => text
     * @param  array<string, string>  $caption  language code => text
     */
    private function __construct(
        public array $alt,
        public array $caption,
        public ?string $title,
    ) {
    }

    /**
     * @param  array<array-key, mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        $title = $input['title'] ?? null;
        $title = is_scalar($title) ? trim((string) $title) : null;

        return new self(
            alt: self::dictionary($input['alt'] ?? null),
            caption: self::dictionary($input['caption'] ?? null),
            title: ($title === null || $title === '') ? null : $title,
        );
    }

    public static function empty(): self
    {
        return new self([], [], null);
    }

    /**
     * Merge into what the record already holds. Unknown keys survive: the
     * column is open by design, and a later version of the panel must not
     * lose what this one does not understand.
     *
     * @param  array<array-key, mixed>|null  $stored
     * @return array<string, mixed>
     */
    public function mergeInto(?array $stored): array
    {
        /** @var array<string, mixed> $metadata */
        $metadata = [];

        foreach ($stored ?? [] as $key => $value) {
            $metadata[(string) $key] = $value;
        }

        self::put($metadata, 'alt', $this->alt === [] ? null : $this->alt);
        self::put($metadata, 'caption', $this->caption === [] ? null : $this->caption);
        self::put($metadata, 'title', $this->title);

        return $metadata;
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<string, string>|string|null  $value
     */
    private static function put(array &$metadata, string $key, array|string|null $value): void
    {
        if ($value === null) {
            unset($metadata[$key]);

            return;
        }

        $metadata[$key] = $value;
    }

    /**
     * @return array<string, string>
     */
    private static function dictionary(mixed $input): array
    {
        if (! is_array($input)) {
            return [];
        }

        $dictionary = [];

        foreach ($input as $locale => $value) {
            $locale = (string) $locale;
            InvalidMediaLocaleException::assert($locale);

            if (! is_scalar($value)) {
                continue;
            }

            $text = trim((string) $value);

            if ($text !== '') {
                $dictionary[$locale] = $text;
            }
        }

        return $dictionary;
    }
}
