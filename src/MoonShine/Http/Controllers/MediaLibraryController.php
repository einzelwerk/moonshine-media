<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\MoonShine\Http\Controllers;

use Ewk\Cms\Media\Enums\FileKind;
use Ewk\Cms\Media\Localization\LanguageCatalog;
use Ewk\Cms\Media\Models\CmsMedia;
use Ewk\Cms\Media\MoonShine\Library\FolderTree;
use Ewk\Cms\Media\MoonShine\Library\LibraryView;
use Ewk\Cms\Media\MoonShine\Library\MediaPresenter;
use Ewk\Cms\Media\MoonShine\Library\MediaRefusal;
use Ewk\Cms\Media\MoonShine\Pages\MediaLibraryPage;
use Ewk\Cms\Media\Queries\MediaQuery;
use Ewk\Cms\Media\Queries\MediaSort;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Throwable;

/**
 * What the media library asks the server while an editor looks through it:
 * one page of the grid, the tree of the folders, and everything about one
 * file.
 *
 * Reading only. Everything that writes lives in
 * {@see MediaLibraryCommandsController} on routes that take a POST, so a
 * command can never be triggered by an address somebody opens.
 *
 * The answers are json and the grid is drawn by the browser, because a grid
 * that filters, pages by cursor and gains a tile the moment an upload
 * finishes is a list the client owns. The markup of a tile stays in the blade
 * of the section all the same: it is one `x-for` template and not a string
 * built in the script.
 */
final class MediaLibraryController extends Controller
{
    public function __construct(
        private readonly MediaPresenter $presenter,
        private readonly FolderTree $tree,
        private readonly LanguageCatalog $languages,
        private readonly LibraryView $library,
    ) {
    }

    /**
     * One page of the grid. `view` is accepted and not read: the tiles and
     * the rows of the list are the same records drawn twice, so switching
     * between them asks the server for nothing.
     */
    public function items(Request $request): JsonResponse
    {
        $query = MediaQuery::make()
            ->search(self::text($request, 'q'))
            ->sort(self::sort($request))
            ->after(self::text($request, 'cursor'));

        $folder = self::folderId($request);

        if ($folder !== null) {
            $query = $query->inFolder($folder);
        }

        $kinds = self::kinds($request);

        if ($kinds !== []) {
            $query = $query->ofKind($kinds);
        }

        if ($request->boolean('without_alt')) {
            $query = $query->withoutAlt($this->languages->defaultLocale());
        }

        if ($request->boolean('unused')) {
            $query = $query->unused();
        }

        $page = $query->get();

        return new JsonResponse([
            'items' => $this->presenter->tiles($page->items),
            'next_cursor' => $page->nextCursor,
        ]);
    }

    public function folders(): JsonResponse
    {
        return new JsonResponse($this->tree->build());
    }

    /**
     * The body of the picker dialog. HTML and not json: it is the grid, the
     * tree and the queue of the section again, drawn by the very same
     * partials, and a second markup of them in a script is how the two
     * screens would drift apart.
     *
     * Everything the field decides arrives here and nothing else: which kinds
     * it takes, whether it takes several files, the folder the editor was last
     * in and the file it already holds. `target` is the key of the asking
     * field, echoed back by the event so that several pickers on one page do
     * not answer for one another.
     */
    public function picker(Request $request): View
    {
        $kinds = self::kinds($request);
        $selected = self::text($request, 'selected');
        $media = $selected === null ? null : MediaQuery::find($selected);
        $folder = self::folderId($request);

        /** @var view-string $view */
        $view = 'moonshine-media::components.media-picker';

        return view($view, [
            'endpoints' => $this->library->endpoints(),
            'labels' => $this->library->labels(),
            'locales' => $this->library->locales(),
            'defaultLocale' => $this->library->defaultLocale(),
            'kindChips' => $this->library->kindChips($kinds),
            'allowedKinds' => $this->library->kindNames($kinds),
            'sorts' => $this->library->sorts(),
            'disks' => $this->library->diskOptions(),
            'singleDisk' => $this->library->isSingleDisk(),
            'defaultDisk' => $this->library->defaultDisk(),
            'tree' => $this->library->tree(),
            'accept' => $this->library->accept($kinds),
            'uploadHints' => $this->library->uploadHints($kinds),
            'multiple' => $request->boolean('multiple'),
            'target' => self::key($request, 'target'),
            'scope' => self::key($request, 'scope'),
            // A file that is already chosen is shown chosen, and the dialog
            // opens where it lies: an editor replacing one picture of a
            // folder of forty starts beside it and not at the top of the
            // library.
            'selected' => $media instanceof CmsMedia ? $this->presenter->tile($media) : null,
            'folder' => $folder ?? ($media instanceof CmsMedia ? $media->folder_id : null),
            'libraryUrl' => self::libraryUrl(),
        ]);
    }

    public function show(string $uuid): JsonResponse
    {
        $media = MediaQuery::find($uuid);

        if (! $media instanceof CmsMedia) {
            return MediaRefusal::notFound();
        }

        return new JsonResponse(['media' => $this->presenter->details($media)]);
    }

    /**
     * The kinds a listing is narrowed to. `kind` is the one chip of the
     * section and `kinds[]` is what a field of a form declares it accepts, so
     * that a picker asking for «a picture or an icon» is one request and not
     * two. Both are read, both are filtered against the enum, and a value
     * that names no kind is dropped rather than refused: the grid of an
     * editor whose address got mangled shows everything.
     *
     * @return list<FileKind>
     */
    private static function kinds(Request $request): array
    {
        $values = $request->input('kinds');
        $values = is_array($values) ? $values : [$values];
        $values[] = $request->input('kind');

        $kinds = [];

        foreach ($values as $value) {
            $kind = is_string($value) ? FileKind::tryFrom(trim($value)) : null;

            if ($kind instanceof FileKind && ! in_array($kind, $kinds, true)) {
                $kinds[] = $kind;
            }
        }

        return $kinds;
    }

    private static function sort(Request $request): MediaSort
    {
        return MediaSort::tryFrom(self::text($request, 'sort') ?? '') ?? MediaSort::NEWEST;
    }

    /**
     * «No folder chosen» is the whole library and not the files outside every
     * folder: the root of the tree is called «Все файлы» and means it.
     */
    private static function folderId(Request $request): ?int
    {
        $folder = $request->input('folder');

        return is_numeric($folder) && (int) $folder > 0 ? (int) $folder : null;
    }

    /**
     * The address of the «Медиа» section, for the one link the dialog offers
     * out of itself. A panel that does not register the page gives none, and
     * the link is then simply not drawn.
     */
    private static function libraryUrl(): ?string
    {
        try {
            return app(MediaLibraryPage::class)->getUrl();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * A name the dialog carries back out of itself: the key of the asking
     * field and the place of the form it stands in. Both are written into the
     * page — one as an attribute, one into the script — so both are narrowed
     * to what a name may be made of rather than trusted to the escaping of the
     * template alone. Anything else in it is dropped and the dialog answers
     * nobody, which is the safe half of the two.
     */
    private static function key(Request $request, string $name): string
    {
        $value = self::text($request, $name) ?? '';

        return preg_match('/^[A-Za-z0-9_\\-\\[\\]]{1,120}$/', $value) === 1 ? $value : '';
    }

    private static function text(Request $request, string $key): ?string
    {
        $value = $request->input($key);

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
