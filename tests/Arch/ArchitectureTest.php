<?php

declare(strict_types=1);

arch('the core of the library does not depend on MoonShine')
    ->expect('Ewk\Cms\Media')
    ->not->toUse('MoonShine')
    ->ignoring('Ewk\Cms\Media\MoonShine');

arch('ewk/moonshine-media does not depend on App')
    ->expect('Ewk\Cms\Media')
    ->not->toUse('App');

arch('ewk/moonshine-media does not depend on a CMS built on it')
    ->expect('Ewk\Cms\Media')
    ->not->toUse([
        'Ewk\Cms\Blocks',
        'Ewk\Cms\Pages',
        'Ewk\Cms\Layouts',
        'Ewk\Cms\Seo',
        'Ewk\MoonShineCms',
    ]);

arch('ewk/moonshine-media has strict types everywhere')
    ->expect('Ewk\Cms\Media')
    ->toUseStrictTypes();

/*
 * The arch expectations above read PHP classes only. A view, a script, a
 * route file or a configuration file names a class in plain text, so those
 * are read as text: the media library has to work without a CMS installed
 * and without the code of any one application.
 */
it('names no class of a CMS or of an application anywhere it ships', function (): void {
    $root = dirname(__DIR__, 2);
    $offenders = [];

    foreach (['src', 'config', 'routes', 'resources', 'lang', 'database'] as $directory) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory, FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile()) {
                continue;
            }

            $content = (string) file_get_contents($file->getPathname());

            if (preg_match('/Ewk\\\\+(?:Cms\\\\+(?:Blocks|Pages|Layouts|Seo)|MoonShineCms)\b|(?<![\w\\\\])App\\\\+[A-Z]/', $content) === 1) {
                $offenders[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }
    }

    expect($offenders)->toBe([]);
});
