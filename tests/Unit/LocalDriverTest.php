<?php

declare(strict_types=1);

use Ewk\Cms\Media\Drivers\LocalDriver;
use Ewk\Cms\Media\Enums\FileKind;
use Ewk\Cms\Media\Upload\PreparedFile;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * @return array{path: string, options: array<string, string>}
 */
function captureWrite(PreparedFile $file, ?string $name = null): array
{
    $captured = ['path' => '', 'options' => []];

    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('put')
        ->once()
        ->andReturnUsing(function (string $path, $contents, array $options) use (&$captured): bool {
            $captured = ['path' => $path, 'options' => $options];

            return true;
        });

    Storage::shouldReceive('disk')->with('public')->andReturn($disk);

    app(LocalDriver::class)->store($file, 'public', 'ab3f9c21-0000-4000-8000-000000000001', 2, $name);

    return $captured;
}

it('writes an original with the headers it will be served with for a year', function (): void {
    $captured = captureWrite(
        PreparedFile::ofBytes(FileKind::IMAGE, 'pretend-png', 'image/png', 'png', 10, 10),
        'Летний вид',
    );

    expect($captured['path'])->toBe('ab/ab3f9c21-0000-4000-8000-000000000001/v2/original.png')
        ->and($captured['options']['ContentType'])->toBe('image/png')
        ->and($captured['options']['CacheControl'])->toBe('public, max-age=31536000, immutable')
        ->and($captured['options']['ContentDisposition'])
        ->toContain("filename*=UTF-8''".rawurlencode('Летний вид.png'))
        // The plain parameter first, for a client that reads only that one,
        // and with nothing in it that a header cannot carry.
        ->and($captured['options']['ContentDisposition'])
        ->toMatch('/^inline; filename="[^"]+\.png"; filename\*=UTF-8/')
        // R2 has no ACLs, so asking for one is an error there and not a
        // setting that is quietly ignored.
        ->and($captured['options'])->not->toHaveKey('visibility');
});

it('shows a pdf in the page', function (): void {
    $captured = captureWrite(PreparedFile::ofBytes(FileKind::DOCUMENT, '%PDF-1.4', 'application/pdf', 'pdf'), 'Rules');

    expect($captured['options']['ContentDisposition'])->toStartWith('inline; ')
        ->and($captured['options']['ContentDisposition'])->toContain('Rules.pdf');
});

it('hands over a document that is not a pdf', function (): void {
    $captured = captureWrite(PreparedFile::ofBytes(FileKind::DOCUMENT, 'x', 'application/msword', 'doc'), 'Rules');

    expect($captured['options']['ContentDisposition'])->toStartWith('attachment; ')
        ->and($captured['options']['ContentDisposition'])->toContain('Rules.doc');
});

it('falls back to a name when the record has none', function (): void {
    $captured = captureWrite(PreparedFile::ofBytes(FileKind::VECTOR, '<svg/>', 'image/svg+xml', 'svg'), '  ');

    expect($captured['options']['ContentDisposition'])
        ->toBe('inline; filename="file.svg"; filename*=UTF-8\'\'file.svg');
});

it('lets nothing of a name into the header that a header cannot carry', function (): void {
    // The name is the one part of the answer an editor wrote, so a quote, a
    // line break and a slash have to be gone before it is a header.
    $captured = captureWrite(
        PreparedFile::ofBytes(FileKind::DOCUMENT, 'x', 'application/msword', 'doc'),
        "re\r\nport\"; x=\\y/z",
    );

    $disposition = $captured['options']['ContentDisposition'];

    preg_match('/^attachment; filename="([^"]*)"; filename\*=UTF-8\'\'(.*)$/', $disposition, $parts);

    expect($parts)->toHaveCount(3)
        ->and($disposition)->not->toContain("\r")
        ->and($disposition)->not->toContain("\n")
        // Two quotes and no more: the ones around the plain name.
        ->and(substr_count($disposition, '"'))->toBe(2)
        // Nothing inside the plain name that ends a parameter, even for a
        // client that splits the header before it reads the quotes.
        ->and($parts[1])->not->toContain(';')
        ->and($parts[1])->not->toContain(',')
        ->and($parts[1])->not->toContain('/')
        ->and($disposition)->toEndWith('.doc');
});
