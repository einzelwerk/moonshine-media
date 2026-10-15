<?php

declare(strict_types=1);

use Ewk\Cms\Media\Enums\FileKind;
use Ewk\Cms\Media\Http\Resources\MediaResource;
use Ewk\Cms\Media\Models\CmsMedia;
use Ewk\Cms\Media\Tests\Fixtures\LibraryFactory;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('public');
});

/**
 * @param  array<string, mixed>|null  $recordAlt
 * @param  array<string, mixed>|null  $override
 */
function resolvedAlt(?array $recordAlt, ?array $override): ?string
{
    $media = LibraryFactory::media(['metadata' => $recordAlt === null ? null : ['alt' => $recordAlt]]);

    $payload = MediaResource::make($media->fresh(['variants']))
        ->withOverride($override === null ? null : ['alt' => $override], 'ru', 'en')
        ->toArray(request());

    return $payload['alt'];
}

it('reads the description in one order and stops at the first one filled in', function (
    ?array $record,
    ?array $override,
    ?string $expected,
): void {
    expect(resolvedAlt($record, $override))->toBe($expected);
})->with([
    'the override in the language asked for wins' => [
        ['ru' => 'Афиша записи'], ['ru' => 'Афиша места'], 'Афиша места',
    ],
    'an override left blank inherits the record' => [
        ['ru' => 'Афиша записи'], ['ru' => ''], 'Афиша записи',
    ],
    'the override falls back to the default language before the record does' => [
        ['ru' => 'Афиша записи'], ['en' => 'Place poster'], 'Place poster',
    ],
    'without an override the record answers' => [
        ['ru' => 'Афиша записи'], null, 'Афиша записи',
    ],
    'the record falls back to the default language' => [
        ['en' => 'Record poster'], null, 'Record poster',
    ],
    'an empty override and an empty record mean no description' => [
        ['ru' => '  '], ['ru' => ''], null,
    ],
    'nothing anywhere is null and not an empty string' => [
        null, null, null,
    ],
    'an override alone is enough' => [
        null, ['ru' => 'Только место'], 'Только место',
    ],
]);

it('takes a description somebody else already resolved', function (): void {
    $media = LibraryFactory::media(['metadata' => ['alt' => ['ru' => 'Афиша записи']]]);

    $payload = MediaResource::make($media->fresh(['variants']))->withAlt('Готовое')->toArray(request());

    expect($payload['alt'])->toBe('Готовое');
});

it('reads the record in the language the application answers in when nobody named one', function (): void {
    config(['app.locale' => 'ru', 'app.fallback_locale' => 'en']);

    $media = LibraryFactory::media(['metadata' => ['alt' => ['ru' => 'Афиша записи']]]);

    expect(MediaResource::make($media->fresh(['variants']))->toArray(request())['alt'])
        ->toBe('Афиша записи');

    // And the fallback of the application, for a record filled in one language.
    $other = LibraryFactory::media(['metadata' => ['alt' => ['en' => 'Record poster']]]);

    expect(MediaResource::make($other->fresh(['variants']))->toArray(request())['alt'])
        ->toBe('Record poster');
});

it('hands out what the file is and never where it lies', function (): void {
    $media = LibraryFactory::media([
        'name' => 'Afisha',
        'kind' => FileKind::VECTOR,
        'mime' => 'image/svg+xml',
        'size' => 2048,
        'checksum' => 'deadbeef',
    ]);

    $payload = MediaResource::make($media->fresh(['variants']))->toArray(request());

    expect($payload)->toHaveKeys(['id', 'url', 'name', 'kind', 'mime', 'size', 'alt', 'placeholder', 'variants', 'pending'])
        ->and($payload['id'])->toBe($media->uuid)
        ->and($payload['name'])->toBe('Afisha')
        ->and($payload['kind'])->toBe('vector')
        ->and($payload['mime'])->toBe('image/svg+xml')
        ->and($payload['size'])->toBe(2048)
        ->and($payload)->not->toHaveKey('disk')
        ->and($payload)->not->toHaveKey('path')
        ->and($payload)->not->toHaveKey('checksum');
});

it('still describes the variants it has', function (): void {
    $media = LibraryFactory::media();
    LibraryFactory::variant($media, 'card');

    $payload = MediaResource::make(CmsMedia::query()->with('variants')->find($media->id))->toArray(request());

    expect($payload['variants'])->toHaveKey('card')
        ->and($payload['pending'])->toBeFalse();
});
