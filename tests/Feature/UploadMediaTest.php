<?php

declare(strict_types=1);

use Ewk\Cms\Media\Actions\UploadMedia;
use Ewk\Cms\Media\Contracts\MediaDriverContract;
use Ewk\Cms\Media\Enums\FileKind;
use Ewk\Cms\Media\Enums\MediaVariantState;
use Ewk\Cms\Media\Events\MediaUploaded;
use Ewk\Cms\Media\Exceptions\FileTooLargeException;
use Ewk\Cms\Media\Exceptions\ImageTooLargeException;
use Ewk\Cms\Media\Exceptions\UnsupportedFileException;
use Ewk\Cms\Media\Http\Resources\MediaResource;
use Ewk\Cms\Media\Jobs\GenerateMediaVariantJob;
use Ewk\Cms\Media\Models\CmsMedia;
use Ewk\Cms\Media\Models\CmsMediaUsage;
use Ewk\Cms\Media\Support\Preset;
use Ewk\Cms\Media\Support\StoredFile;
use Ewk\Cms\Media\Tests\Fixtures\UploadFixtures;
use Ewk\Cms\Media\Upload\PreparedFile;
use Ewk\Cms\Media\Upload\UploadOptions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('public');
});

function mediaUpload(UploadedFile $file, ?UploadOptions $options = null): CmsMedia
{
    $result = app(UploadMedia::class)->handle($file, $options ?? new UploadOptions(disk: 'public'));

    expect($result->isDuplicate())->toBeFalse()
        ->and($result->media)->not->toBeNull();

    return $result->media;
}

function mediaThumbPreset(bool $queued = false): void
{
    Config::set('cms-media.presets', [
        'thumb' => ['width' => 100, 'height' => 80, 'fit' => 'cover', 'format' => 'jpg', 'queued' => $queued],
    ]);
}

/* ------------------------------------------------------------------ paths */

it('stores the original under a uuid-derived path, not the autoincrement id', function (): void {
    $media = mediaUpload(UploadFixtures::image('photo.jpg'));

    expect($media->path)->toContain($media->uuid)
        ->and($media->path)->toContain('/v1/')
        ->and($media->path)->not->toContain('/'.$media->id.'/')
        ->and(Storage::disk('public')->exists($media->path))->toBeTrue();
});

it('fills width and height from the uploaded image', function (): void {
    $media = mediaUpload(UploadFixtures::image('photo.jpg', 640, 480));

    expect($media->width)->toBe(640)
        ->and($media->height)->toBe(480)
        ->and($media->kind)->toBe(FileKind::IMAGE);
});

it('writes the name of the disk it was given into the row and stores the file there', function (): void {
    Storage::fake('s3');
    Config::set('cms-media.disks', [
        ['disk' => 'public', 'label' => 'Main storage', 'default' => true],
        ['disk' => 's3', 'label' => 'Second storage'],
    ]);

    // Different sizes on purpose: two identical pictures are one file for
    // the library, and the second upload would come back as a duplicate.
    $onPublic = mediaUpload(UploadFixtures::image('a.jpg', 40, 30), new UploadOptions());
    $onS3 = mediaUpload(UploadFixtures::image('b.jpg', 41, 30), new UploadOptions(disk: 's3'));

    expect($onPublic->disk)->toBe('public')
        ->and($onS3->disk)->toBe('s3')
        ->and(Storage::disk('public')->exists($onPublic->path))->toBeTrue()
        ->and(Storage::disk('s3')->exists($onS3->path))->toBeTrue();
});

it('refuses a disk that is not in the configuration', function (): void {
    expect(fn () => app(UploadMedia::class)->handle(
        UploadFixtures::image('photo.jpg'),
        new UploadOptions(disk: 'somewhere-else'),
    ))->toThrow(InvalidArgumentException::class);
});

/* --------------------------------------------------------------- refusals */

it('refuses an extension that no kind claims', function (): void {
    expect(fn () => mediaUpload(UploadFixtures::upload('php-script.txt', 'backdoor.phtml')))
        ->toThrow(UnsupportedFileException::class);

    expect(CmsMedia::query()->count())->toBe(0);
});

it('refuses a script that calls itself a picture', function (): void {
    expect(fn () => mediaUpload(UploadFixtures::upload('php-script.txt', 'evil.jpg')))
        ->toThrow(fn (UnsupportedFileException $exception) => expect($exception->status())->toBe(415));

    expect(CmsMedia::query()->count())->toBe(0);
});

it('refuses a picture whose bytes are not the format its name promises', function (): void {
    // A real JPEG offered as a PNG. Both are pictures and both are allowed,
    // so only the check of the extension against the bytes catches it.
    $jpeg = UploadFixtures::image('real.jpg');
    $renamed = UploadFixtures::bytes((string) file_get_contents($jpeg->getRealPath()), 'photo.png');

    expect(fn () => mediaUpload($renamed))->toThrow(UnsupportedFileException::class);
});

it('refuses a file larger than the limit of its kind', function (): void {
    expect(fn () => mediaUpload(UploadFixtures::image('huge.jpg')->size(30 * 1024)))
        ->toThrow(fn (FileTooLargeException $exception) => expect($exception->status())->toBe(413)
            ->and($exception->getMessage())->toContain('30')->toContain('20'));
});

it('refuses a picture whose header declares more pixels than the limit, without decoding it', function (Closure $upload): void {
    expect(fn () => mediaUpload($upload()))->toThrow(ImageTooLargeException::class);

    expect(CmsMedia::query()->count())->toBe(0);
})->with([
    // Forty-five bytes of PNG header claiming forty thousand pixels a side:
    // the header is read, the decoder never is.
    'png' => [fn () => UploadFixtures::upload('megapixel-bomb.png')],
    'jpeg' => [function () {
        // Sixty bytes of JPEG: a start of frame that claims twenty thousand
        // pixels a side. `finfo` calls it a JPEG and the header is measured
        // before any decoder is handed it.
        $jpeg = "\xFF\xD8\xFF\xE0".pack('n', 16)."JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00"
            ."\xFF\xC0".pack('n', 11)."\x08".pack('nn', 20000, 20000)."\x01\x01\x11\x00"
            ."\xFF\xD9";

        return UploadFixtures::bytes($jpeg, 'bomb.jpg');
    }],
    'webp' => [function () {
        $canvas = substr(pack('V', 16383), 0, 3);
        $vp8x = 'VP8X'.pack('V', 10)."\x00\x00\x00\x00".$canvas.$canvas;
        $webp = 'RIFF'.pack('V', 4 + strlen($vp8x)).'WEBP'.$vp8x;

        return UploadFixtures::bytes($webp, 'bomb.webp');
    }],
]);

it('narrows the kinds a caller accepts', function (): void {
    expect(fn () => mediaUpload(
        UploadFixtures::upload('sample.pdf'),
        new UploadOptions(disk: 'public', allowedKinds: [FileKind::IMAGE, FileKind::VECTOR]),
    ))->toThrow(UnsupportedFileException::class);

    $media = mediaUpload(
        UploadFixtures::image('photo.jpg'),
        new UploadOptions(disk: 'public', allowedKinds: [FileKind::IMAGE, FileKind::VECTOR]),
    );

    expect($media->kind)->toBe(FileKind::IMAGE);
});

/* ---------------------------------------------------------------- picture */

it('re-encodes a picture, so that whatever was appended to it is gone', function (): void {
    $media = mediaUpload(UploadFixtures::upload('polyglot.gif'));
    $stored = (string) Storage::disk('public')->get($media->path);

    expect(UploadFixtures::contents('polyglot.gif'))->toContain('<?php')
        ->and($stored)->not->toContain('<?php')
        ->and($media->mime)->toBe('image/gif');
});

it('drops the exif of a picture and rotates it the way the exif asked', function (): void {
    // The fixture is forty by twenty and carries Orientation 6, so a viewer
    // that honours it shows twenty by forty.
    $media = mediaUpload(UploadFixtures::upload('exif-orientation.jpg'));
    $stored = (string) Storage::disk('public')->get($media->path);

    expect(UploadFixtures::contents('exif-orientation.jpg'))->toContain('Exif')
        ->and($stored)->not->toContain('Exif')
        ->and($media->width)->toBe(20)
        ->and($media->height)->toBe(40);
});

it('scales a picture down to the long side of the configuration', function (): void {
    $media = mediaUpload(UploadFixtures::image('wide.jpg', 5000, 100));

    expect($media->width)->toBe(2560)
        ->and($media->height)->toBeGreaterThan(48)
        ->and($media->height)->toBeLessThan(54);
});

it('keeps an animated gif byte for byte', function (): void {
    $media = mediaUpload(UploadFixtures::upload('animated.gif'));

    expect((string) Storage::disk('public')->get($media->path))
        ->toBe(UploadFixtures::contents('animated.gif'))
        ->and($media->width)->toBe(4);
});

it('stores a picture under the extension the bytes deserve', function (): void {
    $media = mediaUpload(UploadFixtures::image('photo.jpeg'));

    expect($media->path)->toEndWith('/original.jpg')
        ->and($media->mime)->toBe('image/jpeg');
});

/* ----------------------------------------------------------------- vector */

it('stores a clean vector as an svg with its size', function (): void {
    $media = mediaUpload(UploadFixtures::upload('clean.svg'));

    expect($media->kind)->toBe(FileKind::VECTOR)
        ->and($media->mime)->toBe('image/svg+xml')
        ->and($media->width)->toBe(48)
        ->and($media->height)->toBe(24)
        ->and($media->path)->toEndWith('/original.svg');
});

it('cleans a hostile vector before storing it', function (): void {
    $media = mediaUpload(UploadFixtures::upload('hostile.svg'));
    $stored = (string) Storage::disk('public')->get($media->path);

    expect($stored)->not->toContain('<script')
        ->and($stored)->not->toContain('foreignObject')
        ->and($stored)->not->toContain('onload')
        ->and($stored)->not->toContain('onclick')
        ->and($stored)->not->toContain('javascript:')
        ->and($stored)->not->toContain('example.com')
        ->and($stored)->toContain('<svg');
});

it('refuses a vector that declares entities', function (): void {
    expect(fn () => mediaUpload(UploadFixtures::upload('entities.svg')))
        ->toThrow(UnsupportedFileException::class);
});

/* --------------------------------------------------------------- document */

it('stores a pdf as a document', function (): void {
    $media = mediaUpload(UploadFixtures::upload('sample.pdf'));

    expect($media->kind)->toBe(FileKind::DOCUMENT)
        ->and($media->mime)->toBe('application/pdf')
        ->and($media->width)->toBeNull()
        ->and(Storage::disk('public')->exists($media->path))->toBeTrue();
});

/* -------------------------------------------------- the other kinds */

it('stores a sound under the extension its bytes deserve', function (): void {
    // An MP3 saved under a `.wav` name. The address of a file is written once
    // and cached for a year, so it is decided by what the file is.
    $media = mediaUpload(UploadFixtures::upload('tone.mp3', 'recording.wav'));

    expect($media->kind)->toBe(FileKind::AUDIO)
        ->and($media->mime)->toBe('audio/mpeg')
        ->and($media->path)->toEndWith('/original.mp3')
        ->and($media->original_name)->toBe('recording.wav');
});

it('takes a legacy office document and keeps the extension of its name', function (): void {
    // `finfo` reports the same container for a `.doc`, an `.xls` and a
    // `.ppt`, so there the name is the only thing that says which it is.
    $media = mediaUpload(UploadFixtures::upload('legacy.doc'));

    expect($media->kind)->toBe(FileKind::DOCUMENT)
        ->and($media->path)->toEndWith('/original.doc');
});

/* ------------------------------------------------------------- duplicates */

it('offers the record that already holds the same bytes instead of storing them twice', function (): void {
    $first = mediaUpload(UploadFixtures::upload('clean.svg'));

    $result = app(UploadMedia::class)->handle(
        UploadFixtures::upload('clean.svg', 'another-name.svg'),
        new UploadOptions(disk: 'public'),
    );

    expect($result->isDuplicate())->toBeTrue()
        ->and($result->media)->toBeNull()
        ->and($result->duplicate->id)->toBe($first->id)
        ->and(CmsMedia::query()->count())->toBe(1);
});

it('looks for a duplicate only among the files the uploader may see', function (): void {
    $ofAdmin = mediaUpload(UploadFixtures::upload('clean.svg'));

    $ofOwner = app(UploadMedia::class)->handle(
        UploadFixtures::upload('clean.svg'),
        new UploadOptions(disk: 'public', ownerType: 'organiser', ownerId: 42),
    );

    // A file of somebody else does not exist for an owner, so it is not
    // offered to them as one they already have either.
    expect($ofOwner->isDuplicate())->toBeFalse()
        ->and($ofOwner->media->checksum)->toBe($ofAdmin->checksum)
        ->and(CmsMedia::query()->count())->toBe(2);

    $again = app(UploadMedia::class)->handle(
        UploadFixtures::upload('clean.svg'),
        new UploadOptions(disk: 'public', ownerType: 'organiser', ownerId: 42),
    );

    expect($again->isDuplicate())->toBeTrue()
        ->and($again->duplicate->id)->toBe($ofOwner->media->id);
});

it('stores a copy all the same when the editor asked for one', function (): void {
    mediaUpload(UploadFixtures::upload('clean.svg'));

    $result = app(UploadMedia::class)->handle(
        UploadFixtures::upload('clean.svg'),
        new UploadOptions(disk: 'public', force: true),
    );

    expect($result->isDuplicate())->toBeFalse()
        ->and(CmsMedia::query()->count())->toBe(2);
});

/* -------------------------------------------------------------- the record */

it('names a file after the upload and lets the caller say otherwise', function (): void {
    $default = mediaUpload(UploadFixtures::image('Summer holiday.jpg', 40, 30));
    $named = mediaUpload(UploadFixtures::image('other.jpg', 41, 30), new UploadOptions(disk: 'public', name: '   Hero picture   '));

    expect($default->name)->toBe('Summer holiday')
        ->and($default->original_name)->toBe('Summer holiday.jpg')
        ->and($named->name)->toBe('Hero picture');
});

it('keeps the uploaded name inside the column it has to fit', function (): void {
    $media = mediaUpload(UploadFixtures::image(str_repeat('a', 300).'.jpg'));

    expect(mb_strlen((string) $media->original_name))->toBe(255)
        ->and(mb_strlen((string) $media->name))->toBe(255);
});

it('records the folder, the owner and the person who uploaded', function (): void {
    $media = mediaUpload(UploadFixtures::image('photo.jpg'), new UploadOptions(
        disk: 'public',
        folderId: null,
        uploadedBy: 17,
        ownerType: 'organiser',
        ownerId: '42',
    ));

    expect($media->uploaded_by)->toBe(17)
        ->and($media->owner_type)->toBe('organiser')
        ->and((string) $media->owner_id)->toBe('42');
});

it('stores the sha256 of the uploaded bytes in the checksum column', function (): void {
    $file = UploadFixtures::image('photo.jpg');
    $expected = hash('sha256', (string) file_get_contents($file->getRealPath()));

    $media = mediaUpload($file);

    expect($media->checksum)->toBe($expected)
        ->and(strlen((string) $media->checksum))->toBe(64);
});

it('raises an event with the stored record', function (): void {
    Event::fake([MediaUploaded::class]);

    $media = mediaUpload(UploadFixtures::image('photo.jpg'));

    Event::assertDispatched(
        MediaUploaded::class,
        static fn (MediaUploaded $event): bool => $event->media->id === $media->id,
    );
});

it('creates no record when the file could not be written', function (): void {
    app()->instance(MediaDriverContract::class, new class () implements MediaDriverContract {
        public function store(PreparedFile $file, string $disk, string $uuid, int $version, ?string $name = null): StoredFile
        {
            throw new RuntimeException('the bucket said no');
        }

        public function variant(CmsMedia $media, Preset $preset): StoredFile
        {
            throw new RuntimeException('not reached');
        }

        public function delete(string $disk, string $path): void
        {
        }

        public function deleteDirectory(string $disk, string $directory): void
        {
        }
    });

    expect(fn () => mediaUpload(UploadFixtures::image('photo.jpg')))->toThrow(RuntimeException::class);
    expect(CmsMedia::query()->count())->toBe(0);
});

/* --------------------------------------------------------------- variants */

it('makes variants for a picture and none for anything else', function (): void {
    mediaThumbPreset();

    $picture = mediaUpload(UploadFixtures::image('photo.jpg', 800, 600));
    $vector = mediaUpload(UploadFixtures::upload('clean.svg'));
    $document = mediaUpload(UploadFixtures::upload('sample.pdf'));

    expect($picture->variants()->count())->toBe(1)
        ->and($vector->variants()->count())->toBe(0)
        ->and($document->variants()->count())->toBe(0);
});

it('generates a sync variant with the requested dimensions and a resolvable url', function (): void {
    mediaThumbPreset();

    $media = mediaUpload(UploadFixtures::image('photo.jpg', 800, 600));
    $variant = $media->variants()->firstWhere('preset', 'thumb');

    expect($variant)->not->toBeNull()
        ->and($variant->state)->toBe(MediaVariantState::READY)
        ->and($variant->width)->toBe(100)
        ->and($variant->height)->toBe(80)
        ->and($variant->path)->toContain('/v1/thumb.jpg')
        ->and(Storage::disk('public')->exists($variant->path))->toBeTrue();

    expect($media->fresh(['variants'])->resolvedVariantUrl('thumb'))
        ->toBe(Storage::disk('public')->url($variant->path));
});

it('sends the generation of a queued preset through the bus contract', function (): void {
    // The job carries no `Dispatchable`: that trait lives in
    // `Illuminate\Foundation`, which is not a component a library can require,
    // so the action asks the container for the dispatcher contract instead.
    Queue::fake();
    mediaThumbPreset(queued: true);

    $media = mediaUpload(UploadFixtures::image('photo.jpg'));
    $variant = $media->variants()->firstOrFail();

    Queue::assertPushed(GenerateMediaVariantJob::class, 1);
    expect($variant->state)->toBe(MediaVariantState::PENDING);
});

it('never returns a broken address for a variant that has not been generated yet', function (): void {
    // The queue of the suite is `sync`, which would run the job the moment the
    // upload commits and leave nothing pending to look at.
    Queue::fake();
    mediaThumbPreset(queued: true);

    $media = mediaUpload(UploadFixtures::image('photo.jpg'));

    $variant = $media->variants()->first();
    expect($variant)->not->toBeNull()
        ->and($variant->state)->toBe(MediaVariantState::PENDING)
        ->and($variant->path)->toBeNull();

    $resource = MediaResource::make($media->fresh(['variants']))->toArray(request());

    expect($resource['pending'])->toBeTrue()
        ->and($resource['variants']['thumb']['url'])->toBe($media->url())
        ->and($resource['variants']['thumb']['url'])->not->toBeEmpty();
});

it('counts usages and rejects deleting a used media record', function (): void {
    $media = mediaUpload(UploadFixtures::image('photo.jpg'));

    CmsMediaUsage::query()->create([
        'cms_media_id' => $media->id,
        'usable_type' => 'page',
        'usable_id' => 1,
        'field' => 'hero',
        'path' => 'items.0.image',
    ]);

    expect($media->usages()->count())->toBe(1);

    expect(fn () => $media->delete())->toThrow(Ewk\Cms\Media\Exceptions\MediaInUseException::class);

    CmsMediaUsage::query()->where('cms_media_id', $media->id)->delete();

    expect($media->delete())->toBeTrue();
});

/* ------------------------------------------- the client name is text only */

it('takes the last extension of a name, in whatever case, and nothing else from it', function (string $name): void {
    // The shape most upload incidents start from: a name that carries an
    // executable extension in the middle. It is a JPEG, so it is stored, and
    // the name it arrived under never reaches a path.
    $media = mediaUpload(UploadFixtures::image($name, 40, 30));

    expect($media->kind)->toBe(FileKind::IMAGE)
        ->and($media->original_name)->toBe($name)
        ->and($media->path)->toEndWith('/original.jpg')
        ->and(strtolower($media->path))->not->toContain('php')
        ->and(strtolower($media->path))->not->toContain('photo');
})->with([
    'an executable extension in the middle' => ['photo.php.jpg'],
    'capitals' => ['PHOTO.JPG'],
]);

it('refuses a name that carries no extension', function (): void {
    foreach (['photo', 'photo.', '.jpg.'] as $name) {
        expect(fn () => mediaUpload(UploadFixtures::bytes(UploadFixtures::contents('sample.pdf'), $name)))
            ->toThrow(UnsupportedFileException::class);
    }

    expect(CmsMedia::query()->count())->toBe(0);
});

it('strips the control characters of an uploaded name and keeps it out of the path', function (): void {
    $jpeg = UploadFixtures::image('real.jpg', 40, 30);
    $media = mediaUpload(UploadFixtures::bytes(
        (string) file_get_contents((string) $jpeg->getRealPath()),
        "../../ev\x00il\r\n.jpg",
    ));

    expect($media->original_name)->toBe('evil.jpg')
        ->and($media->original_name)->not->toContain('..')
        ->and($media->path)->not->toContain('evil')
        ->and($media->path)->not->toContain('..')
        ->and(Storage::disk('public')->exists($media->path))->toBeTrue();
});

/* --------------------------------------------- what the bytes have to say */

it('refuses bytes that finfo cannot name at all', function (): void {
    // `application/octet-stream` is not a type, it is «recognised nothing»,
    // and it must never match a rule whatever an installation listed.
    expect(fn () => mediaUpload(UploadFixtures::bytes(str_repeat("\x8f\x1c\x4a\xd3\x77\x02\xbe\x55", 64), 'photo.jpg')))
        ->toThrow(UnsupportedFileException::class);

    expect(CmsMedia::query()->count())->toBe(0);
});

it('measures the size before it reads the bytes', function (): void {
    // A vector is the expensive one to identify: the whole file is read and
    // parsed as a document. A file over the limit is refused by its size, so
    // nothing that large is ever read.
    // Three megabytes of text that is not a document at all: over the two
    // megabyte limit of a vector, so the refusal has to come from the size
    // and not from the parser.
    $oversized = str_repeat('<g>not a vector</g>', 160_000);

    expect(fn () => mediaUpload(UploadFixtures::bytes($oversized, 'huge.svg')))
        ->toThrow(fn (FileTooLargeException $exception) => expect($exception->status())->toBe(413));

    expect(CmsMedia::query()->count())->toBe(0);
});

it('trims an animated gif at its trailer, so a script glued behind it is not stored', function (): void {
    // The one picture the library keeps byte for byte. Re-encoding is what
    // removes an appended tail everywhere else, so the animation has to be
    // cut at its trailer instead, and this is the case that proves it.
    $animation = UploadFixtures::contents('animated.gif');
    $media = mediaUpload(UploadFixtures::bytes($animation."<?php echo 'pwned'; ?>", 'banner.gif'));

    $stored = (string) Storage::disk('public')->get($media->path);

    expect($stored)->toBe($animation)
        ->and($stored)->not->toContain('<?php')
        ->and(substr($stored, -1))->toBe("\x3B")
        ->and($media->mime)->toBe('image/gif')
        ->and($media->size)->toBe(strlen($animation));
});
