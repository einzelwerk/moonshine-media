<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Support;

use Ewk\Cms\Media\Exceptions\InvalidMediaNameException;

/**
 * The one reading of a name an editor typed, shared by the file and the
 * folder: trimmed, not empty and no longer than the column.
 *
 * The length is counted in characters and not in bytes, because that is what
 * the person typing counts; the column is `varchar(255)`, which MariaDB also
 * counts in characters.
 */
final class MediaName
{
    private function __construct()
    {
    }

    public static function normalize(string $name): string
    {
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > InvalidMediaNameException::MAX_LENGTH) {
            throw InvalidMediaNameException::make();
        }

        return $name;
    }
}
