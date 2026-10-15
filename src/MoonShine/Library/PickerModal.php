<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\MoonShine\Library;

use Illuminate\Container\Container;
use Illuminate\Http\Request;
use MoonShine\UI\Components\Modal;
use Throwable;

/**
 * The one dialog every media field on a page opens.
 *
 * There is exactly one of it per document and not one per field. The body is
 * fetched when it opens, from an address the field builds: the kinds that
 * field takes, whether it takes several files, the folder the editor was last
 * in and the file it already holds. `alwaysLoad` is what makes the second
 * field on the same page get its own answer instead of the one the first got.
 *
 * Which field a chosen file belongs to travels with the answer: the address
 * carries a key of the asking field and the event carries it back, so five
 * pictures on one card never pick for one another.
 *
 * «Once per document» is kept on the request and not in a static, because a
 * static outlives the request under Octane and a page rendered after the first
 * one would then carry no dialog at all.
 *
 * Who draws it: the first field of the page that is not inside a row of a
 * repeater, and the screen that hosts the form if no such field exists. A row
 * of a repeater is excluded on purpose — the panel renders one row as a hidden
 * template and clones it per row, so a dialog drawn there would arrive once
 * per slide and every copy of it would answer the same event.
 *
 * A field and not a page component, because the screens a picture is chosen
 * on have no component in common and a field is the one thing they all share.
 * A screen whose only pictures may live inside a repeater calls {@see once()}
 * itself as well, last, after its fields.
 */
final class PickerModal
{
    public const string NAME = 'cms-media-picker';

    /**
     * The attribute of the request the first field of the page sets: the
     * second one reads it and draws nothing.
     */
    private const string CLAIMED = 'cms-media-picker-modal';

    private function __construct()
    {
    }

    /**
     * The markup of the dialog for the first caller of this request, and an
     * empty string for every caller after it.
     */
    public static function once(): string
    {
        if (! self::claim()) {
            return '';
        }

        try {
            $url = route('moonshine.cms.media.picker');
        } catch (Throwable) {
            // Outside a routed panel — a schema read by a console command —
            // the field still renders, without the dialog it cannot open.
            return '';
        }

        return (string) Modal::make(
            title: (string) __('moonshine-media::library.picker.title'),
            asyncUrl: $url,
        )
            ->name(self::NAME)
            ->wide()
            ->alwaysLoad()
            // The one entry point of a MoonShine modal toggles, so closing one
            // that is already closed opens it again, empty. The mark lets the
            // dialog read its own state before it sends the event, the way the
            // section does it, see `cms-media.js`.
            ->customAttributes(['data-cms-dialog' => self::NAME]);
    }

    /**
     * For an answer that lands inside a page drawn before it, a form loaded
     * into a dialog: the page has its dialog already, so no field of the
     * answer draws one.
     */
    public static function drawnByHost(): void
    {
        self::claim();
    }

    private static function claim(): bool
    {
        try {
            /** @var Request $request */
            $request = Container::getInstance()->make('request');
        } catch (Throwable) {
            return false;
        }

        if ($request->attributes->get(self::CLAIMED) === true) {
            return false;
        }

        $request->attributes->set(self::CLAIMED, true);

        return true;
    }
}
