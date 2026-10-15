<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Localization;

use Illuminate\Contracts\Config\Repository;

/**
 * Default catalog: the languages listed in `cms-media.locales` and
 * `cms-media.default_locale`. An application with languages in the database
 * rebinds {@see LanguageCatalog} with its own implementation.
 *
 * Without configuration the default language is `app.locale` (English when
 * that is not set either) and the catalog is that one language.
 */
final readonly class ConfigLanguageCatalog implements LanguageCatalog
{
    public function __construct(private Repository $config)
    {
    }

    public function locales(): array
    {
        $locales = $this->config->get('cms-media.locales');

        if (! is_array($locales)) {
            return [$this->defaultLocale()];
        }

        $normalized = [];

        foreach ($locales as $locale) {
            if (is_string($locale) && $locale !== '') {
                $normalized[] = $locale;
            }
        }

        return $normalized === [] ? [$this->defaultLocale()] : array_values(array_unique($normalized));
    }

    public function defaultLocale(): string
    {
        foreach ([$this->config->get('cms-media.default_locale'), $this->config->get('app.locale')] as $default) {
            if (is_string($default) && $default !== '') {
                return $default;
            }
        }

        return 'en';
    }
}
