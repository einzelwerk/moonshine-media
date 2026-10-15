<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\MoonShine\Http\Controllers;

use Ewk\Cms\Media\Actions\CreateFolder;
use Ewk\Cms\Media\Actions\DeleteFolder;
use Ewk\Cms\Media\Actions\DeleteMedia;
use Ewk\Cms\Media\Actions\MoveFolder;
use Ewk\Cms\Media\Actions\MoveMedia;
use Ewk\Cms\Media\Actions\RenameFolder;
use Ewk\Cms\Media\Actions\RenameMedia;
use Ewk\Cms\Media\Actions\ReplaceMedia;
use Ewk\Cms\Media\Actions\UpdateMediaMetadata;
use Ewk\Cms\Media\Actions\UploadMedia;
use Ewk\Cms\Media\Enums\FileKind;
use Ewk\Cms\Media\Exceptions\MediaInUseException;
use Ewk\Cms\Media\Models\CmsMedia;
use Ewk\Cms\Media\Models\CmsMediaFolder;
use Ewk\Cms\Media\MoonShine\Library\FolderTree;
use Ewk\Cms\Media\MoonShine\Library\MediaPresenter;
use Ewk\Cms\Media\MoonShine\Library\MediaRefusal;
use Ewk\Cms\Media\MoonShine\Library\MissingFolderException;
use Ewk\Cms\Media\Queries\MediaQuery;
use Ewk\Cms\Media\Support\Actor;
use Ewk\Cms\Media\Support\DeletionReport;
use Ewk\Cms\Media\Support\DiskRegistry;
use Ewk\Cms\Media\Support\MediaMetadata;
use Ewk\Cms\Media\Upload\UploadOptions;
use Ewk\Cms\Media\Usages\MediaUsages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Controller;
use MoonShine\Laravel\MoonShineAuth;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Everything the media library writes.
 *
 * Not one of these methods touches a disk or a row on its own: every one of
 * them is an action of the core of the library (`Ewk\Cms\Media\Actions`)
 * called with an `Actor`, so the rules of the library are the same whether a
 * file is uploaded from this panel, from a console command or from an API of
 * the application.
 *
 * Refusals answer the browser through {@see MediaRefusal}: the exception
 * carries the sentence and the status, and a fault without a status bubbles
 * into the handler of the application, where a fault belongs.
 *
 * Every route here takes a POST and nothing else, which is what the CSRF
 * middleware of the panel needs to stand in front of it. The async methods of
 * a resource, which MoonShine routes with `Route::any`, would need a guard of
 * their own.
 */
final class MediaLibraryCommandsController extends Controller
{
    public function __construct(
        private readonly UploadMedia $upload,
        private readonly ReplaceMedia $replace,
        private readonly RenameMedia $rename,
        private readonly UpdateMediaMetadata $metadata,
        private readonly MoveMedia $move,
        private readonly DeleteMedia $delete,
        private readonly CreateFolder $createFolder,
        private readonly RenameFolder $renameFolder,
        private readonly MoveFolder $moveFolder,
        private readonly DeleteFolder $deleteFolder,
        private readonly MediaUsages $usages,
        private readonly MediaPresenter $presenter,
        private readonly FolderTree $tree,
        private readonly DiskRegistry $disks,
    ) {
    }

    /**
     * One file per request, so that the progress bar of the queue belongs to
     * a file and a refusal names the file it is about.
     */
    public function upload(Request $request): JsonResponse
    {
        $file = $request->file('file');

        if (is_array($file)) {
            $file = $file[0] ?? null;
        }

        if (! $file instanceof UploadedFile) {
            return self::refusal('no_file', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (! $file->isValid()) {
            // A file the web server or PHP cut off never reaches the library,
            // so the sentence is ours and not one of its refusals.
            return in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? self::refusal('too_large', Response::HTTP_REQUEST_ENTITY_TOO_LARGE)
                : self::refusal('no_file', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $result = $this->upload->handle($file, new UploadOptions(
                disk: $this->disk($request),
                folderId: self::folder($request, 'folder')?->id,
                uploadedBy: self::actorId(),
                allowedKinds: self::allowedKinds($request),
                force: $request->boolean('force'),
            ));
        } catch (Throwable $refusal) {
            return self::answer($refusal);
        }

        if ($result->isDuplicate() && $result->duplicate instanceof CmsMedia) {
            return new JsonResponse(['duplicate' => $this->presenter->tile($result->duplicate)]);
        }

        return $result->media instanceof CmsMedia
            ? new JsonResponse(['media' => $this->presenter->tile($result->media)])
            : self::refusal('request_failed', Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * The name an editor typed and the description in every language, in one
     * request: the panel of details saves a field the moment it loses focus,
     * and a second address for the second field would buy nothing.
     */
    public function update(Request $request, string $uuid): JsonResponse
    {
        $media = MediaQuery::find($uuid);

        if (! $media instanceof CmsMedia) {
            return MediaRefusal::notFound();
        }

        try {
            // `exists` and not `input`: the panel turns an empty string into
            // null on the way in, so a name the editor cleared and a name
            // they never sent look the same by the time it gets here. The
            // first has to be refused and the second left alone.
            $name = $request->exists('name') ? $request->string('name')->value() : null;

            if ($name !== null && (trim($name) === '' || trim($name) !== (string) $media->name)) {
                $media = $this->rename->handle($media, $name, self::actor());
            }

            $media = $this->metadata->handle($media, MediaMetadata::fromArray([
                'alt' => self::dictionary($request, 'alt'),
                'caption' => self::dictionary($request, 'caption'),
                // The title is not edited on this screen, and a merge that
                // left it out would drop it.
                'title' => ($media->metadata ?? [])['title'] ?? null,
            ]), self::actor());
        } catch (Throwable $refusal) {
            return self::answer($refusal);
        }

        return new JsonResponse([
            'media' => $this->presenter->details($media),
            'message' => (string) __('moonshine-media::library.messages.saved'),
        ]);
    }

    public function replace(Request $request, string $uuid): JsonResponse
    {
        $media = MediaQuery::find($uuid);

        if (! $media instanceof CmsMedia) {
            return MediaRefusal::notFound();
        }

        $file = $request->file('file');

        if (is_array($file)) {
            $file = $file[0] ?? null;
        }

        if (! $file instanceof UploadedFile) {
            return self::refusal('no_file', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (! $file->isValid()) {
            return in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? self::refusal('too_large', Response::HTTP_REQUEST_ENTITY_TOO_LARGE)
                : self::refusal('no_file', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $media = $this->replace->handle($media, $file, self::actor());
        } catch (Throwable $refusal) {
            return self::answer($refusal);
        }

        return new JsonResponse([
            'media' => $this->presenter->details($media),
            'message' => (string) __('moonshine-media::library.messages.replaced'),
        ]);
    }

    public function move(Request $request): JsonResponse
    {
        $records = self::records($request);

        // An empty selection is not a move of nothing: it is a request that
        // named files the library no longer has, and «Перенесено файлов: 0»
        // would tell the editor it worked.
        if ($records === []) {
            return self::refusal('nothing_chosen', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $this->move->handle($records, self::folder($request, 'folder'), self::actor());
        } catch (Throwable $refusal) {
            return self::answer($refusal);
        }

        $count = count($records);

        return new JsonResponse([
            'ok' => true,
            'moved' => $count,
            'message' => trans_choice('moonshine-media::library.messages.moved', $count, ['count' => $count]),
        ]);
    }

    /**
     * One file or a selection. One file is the details panel asking, and the
     * answer it needs when the file is still on a page is the list of those
     * pages: a report of one line would say «one file stayed» and leave the
     * editor with nowhere to go. A selection gets the report.
     */
    public function delete(Request $request): JsonResponse
    {
        $records = self::records($request);

        if ($records === []) {
            return MediaRefusal::notFound();
        }

        if (count($records) === 1) {
            return $this->deleteOne($records[0]);
        }

        $report = $this->delete->handleMany($records, self::actor());

        return new JsonResponse([
            'report' => self::report($report),
            'message' => self::reportMessage($report),
        ]);
    }

    public function createFolder(Request $request): JsonResponse
    {
        try {
            $folder = $this->createFolder->handle(
                self::folder($request, 'parent'),
                self::name($request),
                self::actor(),
            );
        } catch (Throwable $refusal) {
            return self::answer($refusal);
        }

        return $this->folderAnswer('folder_created', $folder);
    }

    public function renameFolder(Request $request, string $folder): JsonResponse
    {
        $record = self::folderById(self::intOrNull($folder));

        if (! $record instanceof CmsMediaFolder) {
            return MediaRefusal::notFound();
        }

        try {
            $record = $this->renameFolder->handle($record, self::name($request), self::actor());
        } catch (Throwable $refusal) {
            return self::answer($refusal);
        }

        return $this->folderAnswer('folder_renamed', $record);
    }

    public function moveFolder(Request $request, string $folder): JsonResponse
    {
        $record = self::folderById(self::intOrNull($folder));

        if (! $record instanceof CmsMediaFolder) {
            return MediaRefusal::notFound();
        }

        try {
            $record = $this->moveFolder->handle(
                $record,
                self::folder($request, 'parent'),
                self::actor(),
            );
        } catch (Throwable $refusal) {
            return self::answer($refusal);
        }

        return $this->folderAnswer('folder_moved', $record);
    }

    public function deleteFolder(string $folder): JsonResponse
    {
        $record = self::folderById(self::intOrNull($folder));

        if (! $record instanceof CmsMediaFolder) {
            return MediaRefusal::notFound();
        }

        try {
            $this->deleteFolder->handle($record, self::actor());
        } catch (Throwable $refusal) {
            return self::answer($refusal);
        }

        return $this->folderAnswer('folder_deleted', null);
    }

    private function deleteOne(CmsMedia $media): JsonResponse
    {
        try {
            $this->delete->handle($media, self::actor());
        } catch (MediaInUseException) {
            // The sentence of the library names the record by its uuid,
            // which is a line for a log and not for a person. The panel
            // knows the places instead and says them.
            return new JsonResponse([
                'message' => (string) __('moonshine-media::library.confirm.in_use'),
                'usages' => $this->presenter->usageRows($this->usages->of($media)),
            ], Response::HTTP_CONFLICT);
        } catch (Throwable $refusal) {
            return self::answer($refusal);
        }

        return new JsonResponse([
            'report' => self::report(new DeletionReport([$media->uuid])),
            'message' => trans_choice('moonshine-media::library.messages.deleted', 1, ['count' => 1]),
        ]);
    }

    private function folderAnswer(string $message, ?CmsMediaFolder $folder): JsonResponse
    {
        return new JsonResponse([
            'tree' => $this->tree->build(),
            'folder' => $folder instanceof CmsMediaFolder
                ? ['id' => $folder->id, 'name' => $folder->name]
                : null,
            'message' => (string) __('moonshine-media::library.messages.'.$message),
        ]);
    }

    private function disk(Request $request): ?string
    {
        $disk = $request->input('disk');

        // A storage that arrived from a browser is a way to write outside the
        // library, so an unknown name is not an error to report but a value
        // to drop: the upload lands on the storage marked default.
        return is_string($disk) && $this->disks->has($disk) ? $disk : null;
    }

    private static function answer(Throwable $refusal): JsonResponse
    {
        $answer = MediaRefusal::answer($refusal);

        if ($answer === null) {
            throw $refusal;
        }

        return $answer;
    }

    private static function refusal(string $key, int $status): JsonResponse
    {
        return new JsonResponse(
            ['message' => (string) __('moonshine-media::library.messages.'.$key)],
            $status,
        );
    }

    /**
     * The kinds this upload may store, as the field that opened the picker
     * declared them: a field for pictures narrows the allowlist of the
     * configuration to pictures and a video dropped into that dialog is
     * refused with 415.
     *
     * It can only narrow. A name that belongs to no kind is dropped and not
     * refused, and an empty list means the configuration whole: the request
     * decides nothing the server has not already allowed.
     *
     * @return list<FileKind>|null  null: every kind the configuration describes
     */
    private static function allowedKinds(Request $request): ?array
    {
        $values = $request->input('allowed_kinds');
        $values = is_array($values) ? $values : [$values];

        $kinds = [];

        foreach ($values as $value) {
            $kind = is_string($value) ? FileKind::tryFrom(trim($value)) : null;

            if ($kind instanceof FileKind && ! in_array($kind, $kinds, true)) {
                $kinds[] = $kind;
            }
        }

        return $kinds === [] ? null : $kinds;
    }

    /**
     * @return list<CmsMedia>
     */
    private static function records(Request $request): array
    {
        $uuids = $request->input('uuids');
        $uuids = is_array($uuids) ? $uuids : [$uuids];

        $clean = [];

        foreach ($uuids as $uuid) {
            if (is_string($uuid) && $uuid !== '') {
                $clean[] = $uuid;
            }
        }

        if ($clean === []) {
            return [];
        }

        /** @var list<CmsMedia> $records */
        $records = CmsMedia::query()->whereIn('uuid', array_unique($clean))->get()->all();

        return $records;
    }

    /**
     * @return array<string, string>
     */
    private static function dictionary(Request $request, string $key): array
    {
        $values = $request->input($key);

        if (! is_array($values)) {
            return [];
        }

        $dictionary = [];

        foreach ($values as $locale => $value) {
            if (is_string($locale) && is_scalar($value)) {
                $dictionary[$locale] = (string) $value;
            }
        }

        return $dictionary;
    }

    private static function name(Request $request): string
    {
        $name = $request->input('name');

        return is_string($name) ? $name : '';
    }

    /**
     * The folder a request names under `$key`, with three answers and not
     * two: nothing at all, which is the root; a folder; and a name that
     * resolves to no folder, which is a refusal.
     *
     * The third is the reason this is not a lookup inline. Reading an unknown
     * identifier as «no folder chosen» answers a move into a folder somebody
     * has just deleted by quietly moving the files to the root and reporting
     * a success.
     *
     * @throws MissingFolderException
     */
    private static function folder(Request $request, string $key): ?CmsMediaFolder
    {
        $value = $request->input($key);

        if ($value === null || $value === '' || $value === '0' || $value === 0) {
            return null;
        }

        $folder = self::folderById(self::intOrNull($value));

        if (! $folder instanceof CmsMediaFolder) {
            throw MissingFolderException::make();
        }

        return $folder;
    }

    private static function folderById(?int $id): ?CmsMediaFolder
    {
        return $id === null ? null : CmsMediaFolder::query()->find($id);
    }

    private static function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    /**
     * @return array{deleted: list<string>, in_use: list<string>, forbidden: list<string>}
     */
    private static function report(DeletionReport $report): array
    {
        return [
            'deleted' => $report->deleted,
            'in_use' => $report->inUse,
            'forbidden' => $report->forbidden,
        ];
    }

    private static function reportMessage(DeletionReport $report): string
    {
        $deleted = count($report->deleted);
        $stayed = count($report->inUse) + count($report->forbidden);

        if ($stayed === 0) {
            return trans_choice('moonshine-media::library.messages.deleted', $deleted, ['count' => $deleted]);
        }

        if ($deleted === 0) {
            return (string) __('moonshine-media::library.messages.deleted_none');
        }

        return trans_choice('moonshine-media::library.messages.deleted_partial', $stayed, [
            'count' => $stayed,
            'deleted' => $deleted,
        ]);
    }

    private static function actor(): Actor
    {
        return Actor::admin(self::actorId());
    }

    private static function actorId(): ?int
    {
        $id = MoonShineAuth::getGuard()->id();

        return is_numeric($id) ? (int) $id : null;
    }
}
