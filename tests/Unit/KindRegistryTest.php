<?php

declare(strict_types=1);

use Ewk\Cms\Media\Enums\FileKind;
use Ewk\Cms\Media\Support\KindRegistry;

it('looks an extension up regardless of how the client spelled it', function (): void {
    // A camera writes `IMG_0001.JPG` and a phone writes `.jpg`; both are the
    // same allowlist entry, and a leading dot is not a different extension.
    $registry = app(KindRegistry::class);

    expect($registry->kindForExtension('JPG'))->toBe(FileKind::IMAGE)
        ->and($registry->kindForExtension('jpg'))->toBe(FileKind::IMAGE)
        ->and($registry->kindForExtension('.JpG'))->toBe(FileKind::IMAGE)
        ->and($registry->kindForExtension('svg'))->toBe(FileKind::VECTOR)
        ->and($registry->kindForExtension('exe'))->toBeNull();
});

it('looks a mime type up ignoring its parameters and its case', function (): void {
    $registry = app(KindRegistry::class);

    expect($registry->kindForMime('image/png'))->toBe(FileKind::IMAGE)
        ->and($registry->kindForMime('IMAGE/PNG'))->toBe(FileKind::IMAGE)
        ->and($registry->kindForMime('image/svg+xml; charset=us-ascii'))->toBe(FileKind::VECTOR)
        ->and($registry->kindForMime('application/pdf'))->toBe(FileKind::DOCUMENT)
        ->and($registry->kindForMime('audio/x-m4a'))->toBe(FileKind::AUDIO)
        ->and($registry->kindForMime('application/x-php'))->toBeNull();
});

it('hands out the rule of a kind with its limit', function (): void {
    $registry = new KindRegistry([
        'image' => ['extensions' => ['JPG'], 'mimes' => ['image/jpeg'], 'max_size' => 1024],
    ]);

    $rule = $registry->get(FileKind::IMAGE);

    expect($rule->kind)->toBe(FileKind::IMAGE)
        ->and($rule->extensions)->toBe(['jpg'])
        ->and($rule->maxSize)->toBe(1024)
        ->and($rule->allowsExtension('jpg'))->toBeTrue()
        ->and($rule->allowsMime('image/jpeg; charset=binary'))->toBeTrue()
        ->and($rule->allowsMime('image/png'))->toBeFalse()
        ->and($registry->all())->toHaveCount(1);
});

it('refuses a kind that the configuration does not describe', function (): void {
    $registry = new KindRegistry(['image' => ['extensions' => ['jpg'], 'mimes' => ['image/jpeg'], 'max_size' => 1]]);

    expect($registry->has(FileKind::VIDEO))->toBeFalse()
        ->and(fn () => $registry->get(FileKind::VIDEO))->toThrow(InvalidArgumentException::class);
});

it('refuses a kind whose limit was left out', function (): void {
    // Zero is not «no limit»: it is a key that is missing, and it would turn
    // into a refusal of every file of that kind at the third step of an
    // upload, far away from the configuration that caused it.
    expect(fn () => new KindRegistry(['image' => ['extensions' => ['jpg'], 'mimes' => ['image/jpeg']]]))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => new KindRegistry(['image' => ['extensions' => ['jpg'], 'mimes' => ['image/jpeg'], 'max_size' => 0]]))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses a configured kind that is not one of the five', function (): void {
    expect(fn () => new KindRegistry(['archive' => ['extensions' => ['zip']]]))
        ->toThrow(InvalidArgumentException::class);
});

it('describes every kind of the shipped configuration', function (): void {
    $registry = app(KindRegistry::class);

    foreach (FileKind::cases() as $kind) {
        expect($registry->has($kind))->toBeTrue()
            ->and($registry->get($kind)->maxSize)->toBeGreaterThan(0);
    }
});
