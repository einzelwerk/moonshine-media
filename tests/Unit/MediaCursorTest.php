<?php

declare(strict_types=1);

use Ewk\Cms\Media\Queries\MediaCursor;

it('carries the sort key and the identifier there and back', function (): void {
    expect(MediaCursor::decode(MediaCursor::encode('2026-09-22 10:00:00', 41)))
        ->toBe(['key' => '2026-09-22 10:00:00', 'id' => 41])
        ->and(MediaCursor::decode(MediaCursor::encode(2048, 7)))
        ->toBe(['key' => 2048, 'id' => 7]);
});

it('treats anything it cannot read as no cursor at all', function (): void {
    // A mangled address in a browser shows the first page and not an error.
    foreach ([null, '', 'not base64 at all !!', base64_encode('{'), base64_encode('{"k":"a"}'), base64_encode('{"k":"a","i":"7"}')] as $cursor) {
        expect(MediaCursor::decode($cursor))->toBeNull();
    }
});
