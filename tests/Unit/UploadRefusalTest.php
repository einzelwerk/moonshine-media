<?php

declare(strict_types=1);

use Ewk\Cms\Media\Exceptions\CorruptImageException;
use Ewk\Cms\Media\Exceptions\FileTooLargeException;
use Ewk\Cms\Media\Exceptions\ForbiddenMediaActionException;
use Ewk\Cms\Media\Exceptions\ImageTooLargeException;
use Ewk\Cms\Media\Exceptions\UnsupportedFileException;
use Ewk\Cms\Media\Models\CmsMedia;

it('tells an editor in Russian how big the file was and how big it may be', function (): void {
    app()->setLocale('ru');

    $refusal = FileTooLargeException::forSize(24_500_000, 20 * 1024 * 1024);

    expect($refusal->getMessage())->toBe('Файл слишком большой (23,4 МБ). Максимум 20 МБ.');
});

it('names the formats that would have been taken', function (): void {
    app()->setLocale('ru');

    $refusal = UnsupportedFileException::forExtension(['jpg', 'png', 'svg']);

    expect($refusal->getMessage())->toBe('Такой формат не поддерживается. Подойдут: JPG, PNG, SVG');
});

it('counts the megapixels of a picture that is too big', function (): void {
    app()->setLocale('ru');

    $refusal = ImageTooLargeException::forPixels(40000, 40000, 50);

    expect($refusal->getMessage())->toBe('Изображение слишком большое: 1600 мегапикселей. Максимум 50.');
});

it('carries a status the admin package answers with', function (): void {
    $media = new CmsMedia(['uuid' => 'a-b-c']);

    expect(UnsupportedFileException::forContent()->status())->toBe(415)
        ->and(UnsupportedFileException::forReplacement()->status())->toBe(415)
        ->and(FileTooLargeException::forSize(2, 1)->status())->toBe(413)
        ->and(ImageTooLargeException::forPixels(2, 2, 1)->status())->toBe(413)
        ->and(CorruptImageException::make()->status())->toBe(422)
        ->and(ForbiddenMediaActionException::forMedia($media)->status())->toBe(403);
});

it('says the same thing in English', function (): void {
    app()->setLocale('en');

    expect(FileTooLargeException::forSize(24_500_000, 20 * 1024 * 1024)->getMessage())
        ->toBe('The file is too large (23.4 MB). The limit is 20 MB.');
});
