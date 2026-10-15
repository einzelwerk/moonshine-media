<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Exceptions;

use RuntimeException;

/**
 * The keys of `metadata.alt` and `metadata.caption` are language codes. They
 * are checked instead of stored as they came: the same key is later spliced
 * into a json path of a query, and a dictionary keyed by anything at all is a
 * dictionary no reader can address.
 */
final class InvalidMediaLocaleException extends RuntimeException
{
    /** `ru`, `en`, `en-GB`: a language and an optional region. */
    public const string PATTERN = '/^[a-z]{2}(-[A-Za-z]{2})?$/';

    public static function forLocale(string $locale): self
    {
        return new self(MediaMessage::translate(
            'invalid_locale',
            'The language code :locale is not valid.',
            ['locale' => $locale],
        ));
    }

    public static function assert(string $locale): void
    {
        if (preg_match(self::PATTERN, $locale) !== 1) {
            throw self::forLocale($locale);
        }
    }

    public function status(): int
    {
        return 422;
    }
}
