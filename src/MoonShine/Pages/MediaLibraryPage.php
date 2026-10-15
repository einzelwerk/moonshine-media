<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\MoonShine\Pages;

use Ewk\Cms\Media\MoonShine\Components\MediaLibrary;
use MoonShine\Contracts\UI\ComponentContract;
use MoonShine\Laravel\Pages\Page;
use MoonShine\MenuManager\Attributes\SkipMenu;

/**
 * The «Медиа» section.
 *
 * A page of the package and not a resource: the library is a screen of its
 * own and not a table of rows with a form behind each one. A crud resource
 * would have given an editor a list of records with a uuid in the first
 * column, which is the opposite of what a library is for.
 *
 * `SkipMenu` because the item is placed by whoever builds the menu — the
 * application, or a package that groups the library with sections of its
 * own: the autoloader of the menu would add a second one, outside that group.
 * The page itself is registered by the provider of this package.
 */
#[SkipMenu]
final class MediaLibraryPage extends Page
{
    protected ?string $alias = 'cms-media';

    public function getTitle(): string
    {
        return (string) __('moonshine-media::library.title');
    }

    /**
     * @return array<string, string>
     */
    public function getBreadcrumbs(): array
    {
        return ['#' => $this->getTitle()];
    }

    /**
     * @return list<ComponentContract>
     */
    protected function components(): iterable
    {
        return [MediaLibrary::build()];
    }
}
