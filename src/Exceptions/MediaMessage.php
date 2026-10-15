<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Exceptions;

use Illuminate\Container\Container;
use Illuminate\Contracts\Translation\Translator;

/**
 * The one place the refusals of this package turn into a sentence a person
 * reads. Every one of them comes through here, whether it is thrown by the
 * upload pipeline or by an action: {@see UploadRejectedException::translate()}
 * and {@see MediaInUseException} both delegate to it.
 *
 * The translated text where a translator is available, and the plain English
 * one where it is not: the package stays usable outside a booted application,
 * exactly as its models do.
 */
final class MediaMessage
{
    private function __construct()
    {
    }

    /**
     * @param  array<string, int|string>  $replace
     */
    public static function translate(string $key, string $fallback, array $replace = []): string
    {
        $container = Container::getInstance();

        if (! $container->bound(Translator::class)) {
            return self::substitute($fallback, $replace);
        }

        $line = $container->make(Translator::class)->get('cms-media::media.'.$key, $replace);

        // A key nobody translated comes back as the key itself, and a key that
        // holds a group comes back as an array. Neither is a sentence, so the
        // English one is handed over instead.
        return is_string($line) && $line !== 'cms-media::media.'.$key
            ? $line
            : self::substitute($fallback, $replace);
    }

    /**
     * @param  array<string, int|string>  $replace
     */
    private static function substitute(string $text, array $replace): string
    {
        foreach ($replace as $key => $value) {
            $text = str_replace(':'.$key, (string) $value, $text);
        }

        return $text;
    }
}
