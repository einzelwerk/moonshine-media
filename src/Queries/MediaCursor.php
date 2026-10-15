<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Queries;

/**
 * The place in a listing a page ended at: the value of the sort key and the
 * identifier of the last row, base64 over json.
 *
 * Opaque on purpose. The panel passes it back untouched, so the pair inside
 * may change with the order without anything on the other side knowing; and a
 * cursor that does not decode is treated as no cursor at all, because a
 * mangled address in a browser should show the first page and not an error.
 */
final class MediaCursor
{
    private function __construct()
    {
    }

    public static function encode(string|int $key, int $id): string
    {
        return base64_encode((string) json_encode(['k' => $key, 'i' => $id]));
    }

    /**
     * @return array{key: string|int, id: int}|null
     */
    public static function decode(?string $cursor): ?array
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }

        $json = base64_decode($cursor, true);

        if ($json === false) {
            return null;
        }

        $decoded = json_decode($json, true);

        if (! is_array($decoded) || ! isset($decoded['k'], $decoded['i'])) {
            return null;
        }

        $key = $decoded['k'];
        $id = $decoded['i'];

        if (! is_string($key) && ! is_int($key)) {
            return null;
        }

        return is_int($id) ? ['key' => $key, 'id' => $id] : null;
    }
}
