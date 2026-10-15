<?php

declare(strict_types=1);

use Ewk\Cms\Media\Support\DiskRegistry;

it('marks the entry the configuration flagged as the default one', function (): void {
    $registry = new DiskRegistry([
        ['disk' => 'public', 'label' => 'Main storage'],
        ['disk' => 's3', 'label' => 'Cloud', 'default' => true],
    ]);

    expect($registry->default()->disk)->toBe('s3')
        ->and($registry->default()->label)->toBe('Cloud')
        ->and($registry->get('public')->default)->toBeFalse()
        ->and($registry->isSingle())->toBeFalse()
        ->and(array_map(fn ($disk): string => $disk->disk, $registry->all()))->toBe(['public', 's3']);
});

it('falls back to the first entry when nothing is marked as the default', function (): void {
    // A forgotten flag must not break every upload: the storage the
    // installation listed first is a working answer.
    $registry = new DiskRegistry([
        ['disk' => 'public', 'label' => 'Main storage'],
        ['disk' => 's3', 'label' => 'Cloud'],
    ]);

    expect($registry->default()->disk)->toBe('public')
        ->and($registry->default()->default)->toBeTrue();
});

it('labels a disk by its own name when the configuration gives no label', function (): void {
    $registry = new DiskRegistry([['disk' => 'public']]);

    expect($registry->get('public')->label)->toBe('public')
        ->and($registry->isSingle())->toBeTrue()
        ->and($registry->has('public'))->toBeTrue()
        ->and($registry->has('s3'))->toBeFalse();
});

it('refuses a disk it was not configured with', function (): void {
    // This is the check that keeps a disk name from a request out of the
    // library: an unknown name is an error, never a new storage.
    $registry = new DiskRegistry([['disk' => 'public']]);

    expect(fn () => $registry->get('s3'))->toThrow(InvalidArgumentException::class);
});

it('refuses to be built with no storage at all', function (): void {
    expect(fn () => new DiskRegistry([]))->toThrow(InvalidArgumentException::class);
    expect(fn () => new DiskRegistry([['label' => 'Nameless']]))->toThrow(InvalidArgumentException::class);
});
