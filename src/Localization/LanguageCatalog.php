<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Localization;

/**
 * The content languages of the site: the languages a description of a file
 * is written in, and the one the panel treats as the default.
 *
 * The package binds {@see ConfigLanguageCatalog} unless the application has
 * already bound an implementation of its own, for example one that reads the
 * languages from the database.
 */
interface LanguageCatalog
{
    /**
     * Content languages of the site. A single-language site is a catalog of
     * one language; no separate mode flag exists.
     *
     * @return list<string>
     */
    public function locales(): array;

    public function defaultLocale(): string;
}
