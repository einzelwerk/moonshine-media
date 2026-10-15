<?php

declare(strict_types=1);

use Ewk\Cms\Media\Support\StoragePath;

const PATH_TEST_UUID = '9f3c1d2e-0000-4000-8000-000000000001';

it('spreads originals by the first two characters of the uuid and by version', function (): void {
    expect(StoragePath::original(PATH_TEST_UUID, 1, 'jpg'))
        ->toBe('9f/'.PATH_TEST_UUID.'/v1/original.jpg')
        ->and(StoragePath::original(PATH_TEST_UUID, 7, 'JPG'))
        ->toBe('9f/'.PATH_TEST_UUID.'/v7/original.jpg');
});

it('puts a variant next to the original of the same version', function (): void {
    expect(StoragePath::variant(PATH_TEST_UUID, 2, 'thumb', 'webp'))
        ->toBe('9f/'.PATH_TEST_UUID.'/v2/thumb.webp')
        ->and(dirname(StoragePath::variant(PATH_TEST_UUID, 2, 'thumb', 'webp')))
        ->toBe(StoragePath::directory(PATH_TEST_UUID, 2));
});

it('gives a replaced file an address of its own', function (): void {
    // This is why the version is in the path at all: an address is handed out
    // as immutable, so a new file must not reuse the old one.
    expect(StoragePath::directory(PATH_TEST_UUID, 1))
        ->not->toBe(StoragePath::directory(PATH_TEST_UUID, 2));
});
