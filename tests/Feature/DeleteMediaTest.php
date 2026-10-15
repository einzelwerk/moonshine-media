<?php

declare(strict_types=1);

use Ewk\Cms\Media\Actions\DeleteMedia;
use Ewk\Cms\Media\Events\MediaDeleted;
use Ewk\Cms\Media\Exceptions\ForbiddenMediaActionException;
use Ewk\Cms\Media\Exceptions\MediaInUseException;
use Ewk\Cms\Media\Models\CmsMedia;
use Ewk\Cms\Media\Models\CmsMediaVariant;
use Ewk\Cms\Media\Support\Actor;
use Ewk\Cms\Media\Tests\Fixtures\LibraryFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('public');
});

function deleteAction(): DeleteMedia
{
    return app(DeleteMedia::class);
}

it('removes the row, the variants and every byte they accounted for', function (): void {
    $media = LibraryFactory::media();
    $variant = LibraryFactory::variant($media);

    Storage::disk('public')->put($media->path, 'original');
    Storage::disk('public')->put((string) $variant->path, 'thumb');

    deleteAction()->handle($media, Actor::admin());

    expect(CmsMedia::query()->count())->toBe(0)
        ->and(CmsMediaVariant::query()->count())->toBe(0)
        ->and(Storage::disk('public')->allFiles())->toBe([]);
});

it('sweeps the folders of older versions a replacement left behind', function (): void {
    $media = LibraryFactory::media(['version' => 3]);
    $uuid = $media->uuid;
    $directory = substr($uuid, 0, 2).'/'.$uuid;

    Storage::disk('public')->put($directory.'/v1/original.jpg', 'v1');
    Storage::disk('public')->put($directory.'/v2/thumb.webp', 'v2');
    Storage::disk('public')->put($media->path, 'v3');

    deleteAction()->handle($media, Actor::admin());

    expect(Storage::disk('public')->allFiles())->toBe([]);
});

it('removes a path written before the library had versions', function (): void {
    // The rows of the block editor carry `{uuid[0:2]}/{uuid}/original.jpg`
    // without a version in it. The path of the row is what is deleted, never
    // an address rebuilt out of the uuid.
    $uuid = (string) Illuminate\Support\Str::uuid();
    $legacy = substr($uuid, 0, 2).'/'.$uuid.'/original.jpg';
    $media = LibraryFactory::media(['uuid' => $uuid, 'path' => $legacy]);

    Storage::disk('public')->put($legacy, 'old');

    deleteAction()->handle($media, Actor::admin());

    expect(Storage::disk('public')->exists($legacy))->toBeFalse();
});

it('never sweeps a folder that is shared with other files', function (): void {
    // An even older row sits straight under a two character prefix, which
    // every file whose uuid starts the same way shares. Only the file goes.
    $media = LibraryFactory::media(['path' => 'ab/legacy.jpg']);

    Storage::disk('public')->put('ab/legacy.jpg', 'mine');
    Storage::disk('public')->put('ab/somebody-else.jpg', 'theirs');

    deleteAction()->handle($media, Actor::admin());

    expect(Storage::disk('public')->exists('ab/legacy.jpg'))->toBeFalse()
        ->and(Storage::disk('public')->exists('ab/somebody-else.jpg'))->toBeTrue();
});

it('keeps the bytes until the transaction around it has really been committed', function (): void {
    $media = LibraryFactory::media();
    Storage::disk('public')->put($media->path, 'original');

    // A caller with a transaction of its own that rolls back: the row comes
    // back, so its file has to be there still.
    try {
        DB::transaction(function () use ($media): void {
            deleteAction()->handle($media, Actor::admin());

            expect(Storage::disk('public')->exists($media->path))->toBeTrue();

            throw new RuntimeException('rolled back');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(CmsMedia::query()->count())->toBe(1)
        ->and(Storage::disk('public')->exists($media->path))->toBeTrue();

    // And the same caller committing: the sweep runs then. The row is read
    // again, because the model of the rolled back delete no longer believes
    // it exists, whatever the database says.
    $again = CmsMedia::query()->firstOrFail();

    DB::transaction(static fn () => deleteAction()->handle($again, Actor::admin()));

    expect(CmsMedia::query()->count())->toBe(0)
        ->and(Storage::disk('public')->exists($media->path))->toBeFalse();
});

it('refuses a record that content still points at and keeps its file', function (): void {
    $media = LibraryFactory::media();
    Storage::disk('public')->put($media->path, 'original');
    LibraryFactory::usage($media, 'cms_block', 1, 'content', 'items.0.image');

    expect(fn () => deleteAction()->handle($media, Actor::admin()))
        ->toThrow(MediaInUseException::class);

    expect(CmsMedia::query()->count())->toBe(1)
        ->and(Storage::disk('public')->exists($media->path))->toBeTrue();
});

it('refuses an actor the file does not belong to', function (): void {
    $media = LibraryFactory::media(['owner_type' => 'organizer', 'owner_id' => 7]);

    try {
        deleteAction()->handle($media, Actor::owner('organizer', 8));
        $this->fail('The deletion should have been refused.');
    } catch (ForbiddenMediaActionException $exception) {
        expect($exception->status())->toBe(403)
            ->and($exception->uuid)->toBe($media->uuid);
    }

    expect(CmsMedia::query()->count())->toBe(1);
});

it('lets the owner of a file delete it', function (): void {
    $media = LibraryFactory::media(['owner_type' => 'organizer', 'owner_id' => 7]);

    deleteAction()->handle($media, Actor::owner('organizer', '7'));

    expect(CmsMedia::query()->count())->toBe(0);
});

it('announces what went away, for whoever listens', function (): void {
    Event::fake();

    $media = LibraryFactory::media();
    app(DeleteMedia::class)->handle($media, Actor::admin());

    Event::assertDispatched(
        MediaDeleted::class,
        static fn (MediaDeleted $event): bool => $event->uuid === $media->uuid && $event->disk === 'public',
    );
});

it('carries on through a selection and reports every refusal', function (): void {
    $gone = LibraryFactory::media(['name' => 'Free']);
    $used = LibraryFactory::media(['name' => 'Used']);
    $foreign = LibraryFactory::media(['name' => 'Foreign', 'owner_type' => 'organizer', 'owner_id' => 7]);
    $alsoGone = LibraryFactory::media(['name' => 'Free too']);

    LibraryFactory::usage($used, 'cms_block', 1);

    $report = deleteAction()->handleMany(
        [$gone, $used, $foreign, $alsoGone],
        Actor::owner('organizer', 9),
    );

    // The owner may touch none of these, so the whole selection is refused
    // and the loop still reaches the last of them.
    expect($report->forbidden)->toHaveCount(4)
        ->and($report->deleted)->toBe([])
        ->and($report->total())->toBe(4)
        ->and($report->isClean())->toBeFalse();

    $report = deleteAction()->handleMany([$gone, $used, $foreign, $alsoGone], Actor::admin());

    expect($report->deleted)->toBe([$gone->uuid, $foreign->uuid, $alsoGone->uuid])
        ->and($report->inUse)->toBe([$used->uuid])
        ->and($report->forbidden)->toBe([])
        ->and(CmsMedia::query()->count())->toBe(1);
});
