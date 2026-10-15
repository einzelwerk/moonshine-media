<?php

declare(strict_types=1);

use Ewk\Cms\Media\Exceptions\InvalidMediaLocaleException;
use Ewk\Cms\Media\Support\MediaMetadata;

it('drops what was left blank and trims what was not', function (): void {
    $metadata = MediaMetadata::fromArray([
        'alt' => ['ru' => '  Афиша  ', 'en' => '', 'de' => '   '],
        'caption' => ['ru' => 'Открытие'],
        'title' => '  Сезон  ',
    ]);

    expect($metadata->alt)->toBe(['ru' => 'Афиша'])
        ->and($metadata->caption)->toBe(['ru' => 'Открытие'])
        ->and($metadata->title)->toBe('Сезон');
});

it('accepts a language with a region and refuses anything else', function (): void {
    expect(MediaMetadata::fromArray(['alt' => ['en-GB' => 'Poster']])->alt)->toBe(['en-GB' => 'Poster']);

    foreach (['RU', 'russian', 'ru_RU', 'ru-ru-ru', '', 'ru; drop table'] as $locale) {
        expect(fn () => MediaMetadata::fromArray(['alt' => [$locale => 'x']]))
            ->toThrow(InvalidMediaLocaleException::class);
    }
});

it('keeps the keys it does not know about', function (): void {
    $merged = MediaMetadata::fromArray(['alt' => ['ru' => 'Афиша']])
        ->mergeInto(['focus' => ['x' => 0.5], 'alt' => ['ru' => 'Старое'], 'title' => 'Старый']);

    expect($merged)->toBe(['focus' => ['x' => 0.5], 'alt' => ['ru' => 'Афиша']]);
});

it('takes anything that is not a dictionary for an empty one', function (): void {
    $metadata = MediaMetadata::fromArray(['alt' => 'Афиша', 'caption' => null, 'title' => ['nope']]);

    expect($metadata->alt)->toBe([])
        ->and($metadata->caption)->toBe([])
        ->and($metadata->title)->toBeNull()
        ->and($metadata->mergeInto(null))->toBe([]);
});
