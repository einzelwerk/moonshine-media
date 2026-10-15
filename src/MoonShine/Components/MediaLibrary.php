<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\MoonShine\Components;

use Ewk\Cms\Media\MoonShine\Library\LibraryView;
use Ewk\Cms\Media\MoonShine\Library\MediaAssets;
use MoonShine\Contracts\AssetManager\AssetElementContract;
use MoonShine\UI\Components\MoonShineComponent;

/**
 * The «Медиа» section: the tree of folders, the grid, the details panel, the
 * queue of the uploads and the dropzone over the whole screen.
 *
 * Everything the browser needs to draw it is handed over once here, and all of
 * it comes from {@see LibraryView}, which the picker modal reads too: the
 * addresses, the dictionary, the languages of the content, the kinds the
 * configuration allows and the storages the editor may choose from. The screen
 * then talks to the endpoints of the section and to nothing else.
 *
 * The words come from `resources/lang/{ru,en}/library.php` and never from
 * the script: a client that draws a tile still has to say «нет описания» in
 * the language of the panel.
 */
final class MediaLibrary extends MoonShineComponent
{
    protected string $view = 'moonshine-media::components.media-library';

    public function __construct(private readonly LibraryView $library)
    {
        parent::__construct();
    }

    public static function build(): self
    {
        return new self(app(LibraryView::class));
    }

    /**
     * @return list<AssetElementContract>
     */
    protected function assets(): array
    {
        return MediaAssets::inline();
    }

    /**
     * @return array<string, mixed>
     */
    protected function viewData(): array
    {
        return [
            'endpoints' => $this->library->endpoints(),
            'labels' => $this->library->labels(),
            'locales' => $this->library->locales(),
            'defaultLocale' => $this->library->defaultLocale(),
            'kindChips' => $this->library->kindChips(),
            'sorts' => $this->library->sorts(),
            'disks' => $this->library->diskOptions(),
            'singleDisk' => $this->library->isSingleDisk(),
            'defaultDisk' => $this->library->defaultDisk(),
            'tree' => $this->library->tree(),
            'accept' => $this->library->accept(),
            'uploadHints' => $this->library->uploadHints(),
        ];
    }
}
