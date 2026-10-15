<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Exceptions;

use RuntimeException;

/**
 * An upload the library refuses to keep.
 *
 * Every refusal an editor can trigger comes out of this class, and every one
 * of them carries two things: a sentence written for a person, and a status
 * hint. The hint is what lets the admin of the library answer a browser
 * without knowing any of the subclasses, so a refusal added later needs no
 * change there. The statuses are the ones of HTTP: 413 when something is too
 * big, 415 when the type is wrong, 422 for the rest.
 */
abstract class UploadRejectedException extends RuntimeException
{
    public function status(): int
    {
        return 422;
    }

    /**
     * One lookup for the whole package, see {@see MediaMessage}: the
     * translated sentence where a translator is available, and the plain
     * English one where it is not, so that the package stays usable outside a
     * booted application exactly as its models do.
     *
     * The order of the arguments stays as it was, because every subclass
     * calls it.
     *
     * @param  array<string, string|int>  $replace
     */
    protected static function translate(string $key, array $replace, string $fallback): string
    {
        return MediaMessage::translate($key, $fallback, $replace);
    }

    /**
     * A number an editor reads: one decimal, the separator of the language
     * the panel speaks, and no lonely zero after it, so that a limit of
     * twenty megabytes is «20 МБ» and not «20,0 МБ».
     */
    protected static function number(float $value, int $decimals = 1): string
    {
        $separator = self::translate('number_decimal', [], '.');

        if (strlen($separator) !== 1) {
            $separator = '.';
        }

        $formatted = number_format($value, $decimals, $separator, '');
        $tail = $separator.str_repeat('0', $decimals);

        return $decimals > 0 && str_ends_with($formatted, $tail)
            ? substr($formatted, 0, -strlen($tail))
            : $formatted;
    }

    protected static function megabytes(int $bytes): string
    {
        return self::number($bytes / 1024 / 1024);
    }
}
