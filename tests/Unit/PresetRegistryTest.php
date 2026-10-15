<?php

declare(strict_types=1);

use Ewk\Cms\Media\Enums\FileKind;
use Ewk\Cms\Media\Support\FitMode;
use Ewk\Cms\Media\Support\PresetRegistry;

it('builds presets from config and resolves them by name', function (): void {
    $registry = new PresetRegistry([
        'thumb' => ['width' => 200, 'height' => 200, 'fit' => 'cover', 'queued' => false],
    ]);

    expect($registry->has('thumb'))->toBeTrue()
        ->and($registry->has('missing'))->toBeFalse();

    $preset = $registry->get('thumb');

    expect($preset->name)->toBe('thumb')
        ->and($preset->width)->toBe(200)
        ->and($preset->fit)->toBe(FitMode::COVER)
        ->and($preset->queued)->toBeFalse();
});

it('throws for an unknown preset name', function (): void {
    $registry = new PresetRegistry([]);

    expect(fn () => $registry->get('missing'))->toThrow(InvalidArgumentException::class);
});

it('limits a preset to the kinds it names, every kind by default', function (): void {
    $registry = new PresetRegistry([
        'thumb' => ['width' => 200, 'height' => 200],
        'poster' => ['width' => 1920, 'height' => 1920, 'fit' => 'scale-down', 'kinds' => ['video']],
    ]);

    expect($registry->get('thumb')->takes(FileKind::VIDEO))->toBeTrue()
        ->and($registry->get('poster')->fit)->toBe(FitMode::SCALE_DOWN)
        ->and($registry->get('poster')->takes(FileKind::VIDEO))->toBeTrue()
        ->and($registry->get('poster')->takes(FileKind::IMAGE))->toBeFalse();
});

it('refuses a preset that names an unknown kind', function (): void {
    expect(fn () => new PresetRegistry(['poster' => ['width' => 1, 'height' => 1, 'kinds' => ['hologram']]]))
        ->toThrow(InvalidArgumentException::class);
});
