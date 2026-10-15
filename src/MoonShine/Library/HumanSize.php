<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\MoonShine\Library;

/**
 * A size of a file the way an editor reads it: «2,4 МБ» and never a number of
 * bytes.
 *
 * It is formatted on the server and not in the browser, because the units and
 * the decimal separator belong to the language the panel speaks, and the
 * dictionary of the panel lives here.
 */
final class HumanSize
{
    private const int STEP = 1024;

    private function __construct()
    {
    }

    public static function format(int $bytes): string
    {
        $units = self::units();
        $value = (float) max(0, $bytes);
        $unit = 0;

        while ($value >= self::STEP && $unit < count($units) - 1) {
            $value /= self::STEP;
            $unit++;
        }

        // Bytes and kilobytes are whole numbers: a tenth of a kilobyte is a
        // digit nobody reads. From a megabyte on, one decimal is what tells
        // a picture of two megabytes from one of two and a half.
        $decimals = $unit >= 2 ? 1 : 0;

        return self::number($value, $decimals).' '.$units[$unit];
    }

    /**
     * The limit of a kind, as the dropzone spells it out: whole megabytes,
     * because that is how a limit is declared.
     */
    public static function limit(int $bytes): string
    {
        return self::format($bytes);
    }

    private static function number(float $value, int $decimals): string
    {
        $separator = (string) __('moonshine-media::library.decimal');

        if (mb_strlen($separator) !== 1) {
            $separator = '.';
        }

        $formatted = number_format($value, $decimals, $separator, ' ');

        // «20 МБ» and not «20,0 МБ»: a lonely zero after the separator says
        // nothing and makes every limit a character longer.
        $tail = $separator.str_repeat('0', $decimals);

        return $decimals > 0 && str_ends_with($formatted, $tail)
            ? substr($formatted, 0, -strlen($tail))
            : $formatted;
    }

    /**
     * @return list<string>
     */
    private static function units(): array
    {
        $units = __('moonshine-media::library.units');
        $normalized = [];

        if (is_array($units)) {
            foreach ($units as $unit) {
                if (is_string($unit) && $unit !== '') {
                    $normalized[] = $unit;
                }
            }
        }

        return $normalized === [] ? ['B', 'KB', 'MB', 'GB'] : $normalized;
    }
}
