<?php

declare(strict_types=1);

use Ewk\Cms\Media\Models\CmsMedia;
use Ewk\Cms\Media\Support\Actor;

function mediaOwnedBy(?string $type, int|string|null $id): CmsMedia
{
    return new CmsMedia(['owner_type' => $type, 'owner_id' => $id]);
}

it('lets a user of the panel manage anything, owned or not', function (): void {
    $actor = Actor::admin(42);

    expect($actor->isAdmin())->toBeTrue()
        ->and($actor->userId)->toBe(42)
        ->and($actor->owns(mediaOwnedBy('contractor', 1)))->toBeFalse()
        ->and($actor->canManage(mediaOwnedBy('contractor', 1)))->toBeTrue()
        ->and($actor->canManage(mediaOwnedBy(null, null)))->toBeTrue();
});

it('lets an owner manage their own files and nothing else', function (): void {
    $actor = Actor::owner('contractor', 1);

    expect($actor->isAdmin())->toBeFalse()
        ->and($actor->owns(mediaOwnedBy('contractor', 1)))->toBeTrue()
        ->and($actor->canManage(mediaOwnedBy('contractor', 1)))->toBeTrue()
        ->and($actor->owns(mediaOwnedBy('contractor', 2)))->toBeFalse()
        ->and($actor->canManage(mediaOwnedBy('contractor', 2)))->toBeFalse()
        ->and($actor->canManage(mediaOwnedBy('user', 1)))->toBeFalse();
});

it('keeps a file of the administrators out of reach of an owner', function (): void {
    // A record with no owner belongs to the panel. An owner asking for it has
    // to be told the same thing as for someone else's file.
    expect(Actor::owner('contractor', 1)->canManage(mediaOwnedBy(null, null)))->toBeFalse();
});

it('does not care whether the key arrived as a number or as a string', function (): void {
    expect(Actor::owner('contractor', '1')->owns(mediaOwnedBy('contractor', 1)))->toBeTrue()
        ->and(Actor::owner('contractor', 1)->owns(mediaOwnedBy('contractor', '1')))->toBeTrue();
});
