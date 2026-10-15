<?php

declare(strict_types=1);

use Ewk\Cms\Media\Actions\MoveMedia;
use Ewk\Cms\Media\Actions\RenameMedia;
use Ewk\Cms\Media\Actions\UpdateMediaMetadata;
use Ewk\Cms\Media\Exceptions\ForbiddenMediaActionException;
use Ewk\Cms\Media\Exceptions\InvalidMediaNameException;
use Ewk\Cms\Media\Support\Actor;
use Ewk\Cms\Media\Support\MediaMetadata;
use Ewk\Cms\Media\Tests\Fixtures\LibraryFactory;

it('moves files into a folder and back to the root', function (): void {
    $target = LibraryFactory::folder('Afisha');
    $one = LibraryFactory::media();
    $two = LibraryFactory::media();

    app(MoveMedia::class)->handle([$one, $two], $target, Actor::admin());

    expect($one->fresh()->folder_id)->toBe($target->id)
        ->and($two->fresh()->folder_id)->toBe($target->id);

    app(MoveMedia::class)->handle([$one], null, Actor::admin());

    expect($one->fresh()->folder_id)->toBeNull();
});

it('refuses to let an owner file anything into the shared tree', function (): void {
    $target = LibraryFactory::folder('Afisha');
    $media = LibraryFactory::media(['owner_type' => 'organizer', 'owner_id' => 7]);

    expect(fn () => app(MoveMedia::class)->handle([$media], $target, Actor::owner('organizer', 7)))
        ->toThrow(ForbiddenMediaActionException::class);

    expect($media->fresh()->folder_id)->toBeNull();
});

it('renames the file of the panel without touching its address', function (): void {
    $media = LibraryFactory::media();
    $path = $media->path;

    $renamed = app(RenameMedia::class)->handle($media, '  Afisha 2026  ', Actor::admin());

    expect($renamed->name)->toBe('Afisha 2026')
        ->and($renamed->path)->toBe($path)
        ->and($renamed->original_name)->toBe('poster.jpg');
});

it('refuses a name that is empty or longer than the column', function (): void {
    $media = LibraryFactory::media();

    // An empty string and spaces are the same branch once trimmed.
    expect(fn () => app(RenameMedia::class)->handle($media, '   ', Actor::admin()))
        ->toThrow(fn (InvalidMediaNameException $exception) => expect($exception->status())->toBe(422));

    expect(fn () => app(RenameMedia::class)->handle($media, str_repeat('я', 256), Actor::admin()))
        ->toThrow(InvalidMediaNameException::class);

    // Exactly the length of the column is still a name.
    $longest = app(RenameMedia::class)->handle($media, str_repeat('я', 255), Actor::admin());
    expect(mb_strlen((string) $longest->name))->toBe(255);
});

it('refuses to rename a file of another owner', function (): void {
    $media = LibraryFactory::media(['owner_type' => 'organizer', 'owner_id' => 7]);

    expect(fn () => app(RenameMedia::class)->handle($media, 'Mine now', Actor::owner('organizer', 8)))
        ->toThrow(ForbiddenMediaActionException::class);
});

it('merges the description into the metadata and keeps what it does not know', function (): void {
    $media = LibraryFactory::media([
        'metadata' => ['alt' => ['ru' => 'Афиша', 'en' => 'Poster'], 'focus' => ['x' => 0.5]],
    ]);

    $updated = app(UpdateMediaMetadata::class)->handle(
        $media,
        MediaMetadata::fromArray([
            'alt' => ['ru' => ' Новая афиша ', 'en' => '   '],
            'caption' => ['ru' => 'Открытие'],
            'title' => ' Сезон 2026 ',
        ]),
        Actor::admin(),
    );

    expect($updated->fresh()->metadata)->toBe([
        'alt' => ['ru' => 'Новая афиша'],
        'focus' => ['x' => 0.5],
        'caption' => ['ru' => 'Открытие'],
        'title' => 'Сезон 2026',
    ]);
});

it('drops a dictionary that was emptied instead of storing an empty one', function (): void {
    $media = LibraryFactory::media(['metadata' => ['alt' => ['ru' => 'Афиша']]]);

    $updated = app(UpdateMediaMetadata::class)->handle($media, MediaMetadata::empty(), Actor::admin());

    expect($updated->fresh()->metadata)->toBeNull();
});

it('refuses metadata of a file of another owner', function (): void {
    $media = LibraryFactory::media(['owner_type' => 'organizer', 'owner_id' => 7]);

    expect(fn () => app(UpdateMediaMetadata::class)->handle(
        $media,
        MediaMetadata::fromArray(['alt' => ['ru' => 'Чужая']]),
        Actor::owner('organizer', 8),
    ))->toThrow(ForbiddenMediaActionException::class);
});
