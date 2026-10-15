<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Queries;

use DateTimeInterface;
use Ewk\Cms\Media\Models\CmsMedia;

/**
 * The three orders the grid of the library offers.
 *
 * Every one of them ends in the identifier, and in the same direction as the
 * order itself. Without that tie-break a cursor cannot exist: two files of
 * the same second, the same name or the same size would have no stable place
 * between pages, and one of them would be shown twice or not at all.
 */
enum MediaSort: string
{
    case NEWEST = 'newest';
    case NAME = 'name';
    case SIZE_DESC = 'size_desc';

    /**
     * The sql the rows are ordered and the cursor is compared by. `name` is
     * nullable, and a null orders differently in MariaDB and in sqlite, so it
     * is read as an empty string in both places.
     *
     * @return literal-string
     */
    public function expression(): string
    {
        return match ($this) {
            self::NEWEST => 'created_at',
            self::NAME => "coalesce(name, '')",
            self::SIZE_DESC => 'size',
        };
    }

    /** @return 'asc'|'desc' */
    public function direction(): string
    {
        return match ($this) {
            self::NAME => 'asc',
            self::NEWEST, self::SIZE_DESC => 'desc',
        };
    }

    /**
     * The value of the sort key for one row, in the shape the cursor carries
     * it. A timestamp travels as `Y-m-d H:i:s`, which both databases compare
     * against a datetime column the way they compare two of their own.
     */
    public function keyOf(CmsMedia $media): string|int
    {
        return match ($this) {
            self::NEWEST => self::timestamp($media->getAttribute('created_at')),
            self::NAME => (string) ($media->name ?? ''),
            self::SIZE_DESC => $media->size,
        };
    }

    private static function timestamp(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return is_scalar($value) ? (string) $value : '';
    }
}
