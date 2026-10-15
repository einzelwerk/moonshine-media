<?php

declare(strict_types=1);

use Ewk\Cms\Media\Actions\ReplaceMedia;
use Ewk\Cms\Media\Actions\UploadMedia;
use Ewk\Cms\Media\Events\MediaReplaced;
use Ewk\Cms\Media\Exceptions\ForbiddenMediaActionException;
use Ewk\Cms\Media\Exceptions\UnsupportedFileException;
use Ewk\Cms\Media\Models\CmsMedia;
use Ewk\Cms\Media\Models\CmsMediaUsage;
use Ewk\Cms\Media\Support\Actor;
use Ewk\Cms\Media\Tests\Fixtures\UploadFixtures;
use Ewk\Cms\Media\Upload\UploadOptions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('public');
});

function replaceable(?string $ownerType = null, int|string|null $ownerId = null): CmsMedia
{
    $result = app(UploadMedia::class)->handle(
        UploadFixtures::image('original.jpg', 200, 100),
        // `force`, because a test may want two records of the same picture.
        new UploadOptions(disk: 'public', ownerType: $ownerType, ownerId: $ownerId, force: true, name: 'Hero'),
    );

    return $result->media;
}

function replaceWith(CmsMedia $media, UploadedFile $file, ?Actor $actor = null): CmsMedia
{
    return app(ReplaceMedia::class)->handle($media, $file, $actor ?? Actor::admin(1));
}

it('writes the new file into the next version and removes the previous one', function (): void {
    Config::set('cms-media.presets', [
        'thumb' => ['width' => 50, 'height' => 50, 'fit' => 'cover', 'format' => 'jpg', 'queued' => false],
    ]);

    $media = replaceable();
    $oldOriginal = $media->path;
    $oldVariant = (string) $media->variants()->firstOrFail()->path;

    $replaced = replaceWith($media, UploadFixtures::image('newer.jpg', 120, 60));

    expect($replaced->version)->toBe(2)
        ->and($replaced->path)->toContain('/v2/')
        ->and($replaced->width)->toBe(120)
        ->and($replaced->height)->toBe(60)
        ->and($replaced->uuid)->toBe($media->uuid)
        ->and(Storage::disk('public')->exists($replaced->path))->toBeTrue()
        ->and(Storage::disk('public')->exists($oldOriginal))->toBeFalse()
        ->and(Storage::disk('public')->exists($oldVariant))->toBeFalse();

    $variant = $replaced->variants()->firstOrFail();

    expect($replaced->variants()->count())->toBe(1)
        ->and((string) $variant->path)->toContain('/v2/')
        ->and(Storage::disk('public')->exists((string) $variant->path))->toBeTrue();
});

it('keeps the name, the metadata, the folder and the usages of the record', function (): void {
    $media = replaceable();
    $media->forceFill(['metadata' => ['alt' => ['ru' => 'Обложка']]])->save();

    CmsMediaUsage::query()->create([
        'cms_media_id' => $media->id,
        'usable_type' => 'page',
        'usable_id' => 7,
        'field' => 'hero',
        'path' => 'items.0.image',
    ]);

    $replaced = replaceWith($media, UploadFixtures::image('newer.jpg', 120, 60));

    expect($replaced->name)->toBe('Hero')
        ->and($replaced->metadata['alt']['ru'])->toBe('Обложка')
        ->and($replaced->original_name)->toBe('newer.jpg')
        ->and($replaced->usages()->count())->toBe(1);
});

it('refuses a replacement of another kind', function (): void {
    $media = replaceable();

    expect(fn () => replaceWith($media, UploadFixtures::upload('sample.pdf')))
        ->toThrow(UnsupportedFileException::class);

    expect($media->fresh()->version)->toBe(1);
});

it('refuses a replacement the actor does not own and allows the one it does', function (): void {
    $mine = replaceable('organiser', 42);
    $foreign = replaceable('organiser', 43);

    expect(fn () => replaceWith($foreign, UploadFixtures::image('newer.jpg'), Actor::owner('organiser', 42)))
        ->toThrow(ForbiddenMediaActionException::class);

    $replaced = replaceWith($mine, UploadFixtures::image('newer.jpg'), Actor::owner('organiser', 42));

    expect($replaced->version)->toBe(2)
        ->and($foreign->fresh()->version)->toBe(1);
});

it('lets an administrator replace a file that belongs to somebody else', function (): void {
    $media = replaceable('organiser', 42);

    expect(replaceWith($media, UploadFixtures::image('newer.jpg'), Actor::admin(1))->version)->toBe(2);
});

it('says which address the record used to have', function (): void {
    Event::fake([MediaReplaced::class]);

    $media = replaceable();
    $previous = $media->path;

    replaceWith($media, UploadFixtures::image('newer.jpg'));

    Event::assertDispatched(
        MediaReplaced::class,
        static fn (MediaReplaced $event): bool => $event->previousPath === $previous && $event->media->version === 2,
    );
});

it('cleans a replacing vector the same way an upload does', function (): void {
    $result = app(UploadMedia::class)->handle(
        UploadFixtures::upload('clean.svg'),
        new UploadOptions(disk: 'public'),
    );

    $replaced = replaceWith($result->media, UploadFixtures::upload('hostile.svg'));
    $stored = (string) Storage::disk('public')->get($replaced->path);

    expect($replaced->version)->toBe(2)
        ->and($stored)->not->toContain('<script')
        ->and($stored)->not->toContain('onload');
});

it('leaves the folder, the owner and the person who uploaded where they were', function (): void {
    $folder = Ewk\Cms\Media\Models\CmsMediaFolder::query()->create(['name' => 'Covers']);

    $media = app(UploadMedia::class)->handle(
        UploadFixtures::image('original.jpg', 200, 100),
        new UploadOptions(
            disk: 'public',
            folderId: $folder->id,
            uploadedBy: 17,
            ownerType: 'organiser',
            ownerId: 42,
            name: 'Hero',
        ),
    )->media;

    // Read before the replacement: the action force-fills the very object it
    // was handed, so the old value is no longer on it afterwards.
    $previousChecksum = $media->checksum;

    $replaced = replaceWith($media, UploadFixtures::image('newer.jpg', 120, 60), Actor::owner('organiser', 42));

    expect($replaced->folder_id)->toBe($folder->id)
        ->and($replaced->uploaded_by)->toBe(17)
        ->and($replaced->owner_type)->toBe('organiser')
        ->and((string) $replaced->owner_id)->toBe('42')
        ->and($replaced->disk)->toBe('public')
        ->and($replaced->checksum)->not->toBe($previousChecksum);
});

it('writes nothing of the new version when the record could not be saved', function (): void {
    $media = replaceable();
    $previousPath = $media->path;

    // A listener of the model is the cheapest way to make the save fail
    // after the file of the new version is already on the disk.
    CmsMedia::saving(static function (): void {
        throw new RuntimeException('the row said no');
    });

    try {
        expect(fn () => replaceWith($media, UploadFixtures::image('newer.jpg', 120, 60)))
            ->toThrow(RuntimeException::class);
    } finally {
        CmsMedia::flushEventListeners();
    }

    expect(Storage::disk('public')->exists($previousPath))->toBeTrue()
        ->and(Storage::disk('public')->allFiles(dirname(dirname($previousPath)).'/v2'))->toBe([])
        ->and($media->fresh()->version)->toBe(1)
        ->and($media->fresh()->path)->toBe($previousPath);
});
