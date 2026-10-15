<?php

declare(strict_types=1);

use Ewk\Cms\Media\Localization\ConfigLanguageCatalog;
use Ewk\Cms\Media\Localization\LanguageCatalog;

it('falls back to the language of the application when nothing is configured', function (): void {
    $catalog = app(LanguageCatalog::class);

    config()->set('app.locale', 'de');

    expect($catalog)->toBeInstanceOf(ConfigLanguageCatalog::class)
        ->and($catalog->defaultLocale())->toBe('de')
        ->and($catalog->locales())->toBe(['de']);

    // An application without a language of its own is an English one.
    config()->set('app.locale', '');

    expect($catalog->defaultLocale())->toBe('en')
        ->and($catalog->locales())->toBe(['en']);
});

it('reads the languages of the library from its configuration', function (): void {
    $catalog = app(LanguageCatalog::class);

    config()->set('cms-media.locales', ['ru', 'en', 'ru', '']);
    config()->set('cms-media.default_locale', 'ru');

    expect($catalog->locales())->toBe(['ru', 'en'])
        ->and($catalog->defaultLocale())->toBe('ru');

    // A list that names nothing is a catalog of the default language alone.
    config()->set('cms-media.locales', []);

    expect($catalog->locales())->toBe(['ru']);
});

it('leaves a catalog the application bound itself alone', function (): void {
    $own = new class () implements LanguageCatalog {
        public function locales(): array
        {
            return ['fr'];
        }

        public function defaultLocale(): string
        {
            return 'fr';
        }
    };

    $app = $this->app;
    $app->instance(LanguageCatalog::class, $own);
    (new Ewk\Cms\Media\CmsMediaServiceProvider($app))->register();

    expect(app(LanguageCatalog::class))->toBe($own);
});
