<?php

declare(strict_types=1);

use Ewk\Cms\Media\Actions\UploadMedia;
use Ewk\Cms\Media\Contracts\VideoFramesContract;
use Ewk\Cms\Media\Enums\FileKind;
use Ewk\Cms\Media\Enums\MediaVariantState;
use Ewk\Cms\Media\Models\CmsMedia;
use Ewk\Cms\Media\Tests\Fixtures\FakeVideoFrames;
use Ewk\Cms\Media\Tests\Fixtures\UploadFixtures;
use Ewk\Cms\Media\Upload\UploadOptions;
use Ewk\Cms\Media\Video\FfmpegVideoFrames;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    Storage::fake('public');

    Config::set('cms-media.presets', [
        'thumb' => ['width' => 20, 'height' => 20, 'fit' => 'cover', 'format' => 'jpg', 'queued' => false],
        'poster' => ['width' => 1920, 'height' => 1920, 'fit' => 'scale-down', 'format' => 'webp', 'queued' => false, 'kinds' => ['video']],
        'avatar' => ['width' => 10, 'height' => 10, 'fit' => 'cover', 'format' => 'jpg', 'queued' => false, 'kinds' => ['image']],
    ]);
});

function videoFrames(FakeVideoFrames $frames): FakeVideoFrames
{
    app()->instance(VideoFramesContract::class, $frames);

    return $frames;
}

function uploadVideo(): CmsMedia
{
    $result = app(UploadMedia::class)->handle(
        UploadFixtures::bytes(FakeVideoFrames::mp4(), 'loop.mp4'),
        new UploadOptions(disk: 'public'),
    );

    return $result->media ?? throw new RuntimeException('The video was not stored.');
}

it('cuts the variants of a video from its frame, only of the presets that take a video', function (): void {
    $frames = videoFrames(new FakeVideoFrames());

    $media = uploadVideo();
    $variants = $media->variants()->get()->keyBy('preset');

    expect($media->kind)->toBe(FileKind::VIDEO)
        // The size the video is shown at, the phone that shot it upright.
        ->and($media->width)->toBe(1080)
        ->and($media->height)->toBe(1920)
        ->and($variants->keys()->sort()->values()->all())->toBe(['poster', 'thumb'])
        ->and($variants['thumb']->state)->toBe(MediaVariantState::READY)
        ->and($variants['poster']->state)->toBe(MediaVariantState::READY)
        ->and($variants['poster']->format)->toBe('webp')
        // `scale-down` never enlarges a frame and never pads it.
        ->and($variants['poster']->width)->toBe(64)
        ->and($variants['poster']->height)->toBe(48)
        ->and(Storage::disk('public')->exists((string) $variants['poster']->path))->toBeTrue()
        // A file of this server is read where it lies, not copied first.
        ->and($frames->sources)->each->toBe(Storage::disk('public')->path($media->path));
});

it('leaves a video without variants where no frames are cut', function (): void {
    videoFrames(new FakeVideoFrames(available: false));

    $media = uploadVideo();

    expect($media->variants()->count())->toBe(0)
        ->and($media->width)->toBeNull()
        ->and($media->previewUrl('thumb'))->toBeNull();
});

it('keeps the upload of a video whose size cannot be read', function (): void {
    Config::set('cms-media.presets', []);
    videoFrames(new FakeVideoFrames(broken: true));

    $media = uploadVideo();

    expect($media->width)->toBeNull()
        ->and($media->height)->toBeNull();
});

it('offers a picture of a video only once its frame is cut', function (): void {
    videoFrames(new FakeVideoFrames());
    $media = uploadVideo();

    expect($media->load('variants')->previewUrl('thumb'))
        ->toBe(Storage::disk('public')->url((string) $media->variants->firstWhere('preset', 'thumb')?->path));

    $media->variants()->update(['state' => MediaVariantState::PENDING->value]);

    // The address of the video itself is never offered as a picture.
    expect($media->load('variants')->previewUrl('thumb'))->toBeNull();
});

it('puts in line the variants a video lacks, and nothing the second time', function (): void {
    videoFrames(new FakeVideoFrames(available: false));
    $media = uploadVideo();

    videoFrames(new FakeVideoFrames());

    $this->artisan('cms-media:variants', ['--kind' => 'video'])
        ->expectsOutput('Variants put in line: 2.')
        ->assertSuccessful();

    $this->artisan('cms-media:variants', ['--kind' => 'video'])
        ->expectsOutput('Variants put in line: 0.')
        ->assertSuccessful();

    // A failed one is made again, the ready one is left alone.
    $media->variants()->where('preset', 'poster')->update(['state' => MediaVariantState::FAILED->value]);

    $this->artisan('cms-media:variants')
        ->expectsOutput('Variants put in line: 1.')
        ->assertSuccessful();

    expect($media->variants()->where('state', MediaVariantState::READY->value)->count())->toBe(2);
});

it('refuses a kind it does not know', function (): void {
    $this->artisan('cms-media:variants', ['--kind' => 'hologram'])->assertFailed();
});

it('reads a real frame and the size with ffmpeg', function (): void {
    $finder = new ExecutableFinder();

    if ($finder->find('ffmpeg') === null || $finder->find('ffprobe') === null) {
        $this->markTestSkipped('ffmpeg is not installed here.');
    }

    $long = tempnam(sys_get_temp_dir(), 'cms-video-').'.mp4';
    $short = tempnam(sys_get_temp_dir(), 'cms-video-').'.mp4';

    foreach ([[$long, '2'], [$short, '0.3']] as [$path, $seconds]) {
        (new Process([
            'ffmpeg', '-v', 'error', '-y', '-f', 'lavfi', '-i', 'testsrc=size=64x48:rate=10',
            '-t', $seconds, '-pix_fmt', 'yuv420p', $path,
        ]))->mustRun();
    }

    Config::set('cms-media.video', ['frames' => true]);
    $frames = app(FfmpegVideoFrames::class);

    try {
        expect($frames->available())->toBeTrue()
            ->and($frames->dimensions($long))->toBe([64, 48])
            ->and($frames->frame($long))->toStartWith("\x89PNG")
            // Shorter than the moment asked for: the first frame instead.
            ->and($frames->frame($short))->toStartWith("\x89PNG");
    } finally {
        @unlink($long);
        @unlink($short);
    }
});

it('is not available while the installation has not turned it on', function (): void {
    Config::set('cms-media.video', ['frames' => false]);

    expect(app(FfmpegVideoFrames::class)->available())->toBeFalse();
});
