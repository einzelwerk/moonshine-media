<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\MoonShine\Library;

use MoonShine\AssetManager\InlineCss;
use MoonShine\AssetManager\InlineJs;
use MoonShine\Contracts\AssetManager\AssetElementContract;

/**
 * The stylesheet and the script of the media library and of its fields,
 * inlined from the files they live in: the package ships no build step, and
 * a published asset would have to be republished after every change of a file
 * the application does not own.
 *
 * They are asked for by the screen that draws the library and by the fields
 * that open it, and by nothing else, so a form of the panel that carries
 * neither pays for none of it. The asset manager merges identical inline
 * assets, so a page that draws the library twice still ships one copy.
 */
final readonly class MediaAssets
{
    /**
     * @return list<AssetElementContract>
     */
    public static function inline(): array
    {
        $assets = [];

        $files = [
            '/../../../resources/css/cms-media.css' => InlineCss::class,
            '/../../../resources/js/cms-media.js' => InlineJs::class,
        ];

        foreach ($files as $path => $element) {
            $content = file_get_contents(__DIR__.$path);

            if ($content !== false) {
                $assets[] = $element::make($content);
            }
        }

        return $assets;
    }
}
