# MoonShine Media

A media library for Laravel with an admin for [MoonShine 4](https://moonshine-laravel.com):
files, folders, variants, usages and the pictures a record owns, plus the «Медиа»
section, a picture field, a gallery field and a listing column for the panel.

The library itself works without any CMS: a tour cover, the logo of a venue or the
avatar of a user is an attachment of that model, read and written through the same
code a page builder would use. A CMS built on top of it registers its own records
through the extension points described below.

Status: **0.x** — the API may still change between minor versions.

## Requirements

- PHP 8.4
- Laravel 13
- MoonShine 4.19+
- GD or Imagick; `ffmpeg` and `ffprobe` only if video frames are turned on
- MariaDB or SQLite are what the suites run on; MySQL and PostgreSQL are untested

## Installation

```bash
composer require ewk/moonshine-media
php artisan migrate
```

Both service providers are discovered automatically:

- `Ewk\Cms\Media\CmsMediaServiceProvider` — the library itself: models, actions,
  queries, the upload pipeline, the `cms-media:variants` command. It knows nothing of
  the panel.
- `Ewk\Cms\Media\MoonShine\MoonShineMediaServiceProvider` — the admin: the «Медиа» page,
  its endpoints inside the panel, the fields, their views, words and configuration.

Publishing is optional:

```bash
php artisan vendor:publish --tag=cms-media-config        # config/cms-media.php
php artisan vendor:publish --tag=moonshine-media-config  # config/moonshine-media.php
php artisan vendor:publish --tag=cms-media-lang          # lang/vendor/cms-media
php artisan vendor:publish --tag=moonshine-media-lang    # lang/vendor/moonshine-media
php artisan vendor:publish --tag=moonshine-media-views   # resources/views/vendor/moonshine-media
php artisan vendor:publish --tag=cms-media-migrations
```

The «Медиа» page is registered with the panel but marked `#[SkipMenu]`, so it is placed
wherever the menu of the application wants it:

```php
use Ewk\Cms\Media\MoonShine\Pages\MediaLibraryPage;
use MoonShine\MenuManager\MenuItem;

MenuItem::make(MediaLibraryPage::class, static fn (): string => __('moonshine-media::library.title'), 'photo'),
```

## The data

- **`cms_media`** — one row per file: uuid, `kind`, disk, path, mime, size, dimensions,
  checksum, `version`, a placeholder and an open json `metadata` (the default `alt`,
  `caption` and `title`). Whatever refers to a file stores the uuid and never a path, so
  a file that moves keeps every reference it had. `uploaded_by` and the polymorphic
  `owner_type`/`owner_id` say who put it there: empty for the files of administrators,
  filled when someone uploads through an API of their own, and then read and deleted
  filtered by the owner.
- **`cms_media_folders`** — the tree the editor arranges the library with, `parent_id`
  and a name unique under one parent. Folders are virtual: a path on a disk is built from
  the uuid alone, so renaming or moving one changes no address. Only an empty folder is
  deleted.
- **`cms_media_variants`** — a derivative per named preset, with a state
  (`PENDING`/`READY`/`FAILED`). A variant that is not ready hands out the nearest ready
  one or the original rather than a broken address.
- **`cms_media_usages`** — who points at a record, so deleting one that is still in use is
  refused (`MediaInUseException`) instead of leaving holes on pages. The same table holds
  the pictures a record owns, see «Attachments» below.
- **Kinds** — `image`, `vector`, `video`, `audio`, `document` (`FileKind`). Which
  extensions and MIME types belong to a kind, and how large a file of it may be, is the
  allowlist in `cms-media.kinds`; nothing about it ever comes from a request.
- **Storages** — `cms-media.disks` lists the disks the editor may upload to, with a
  caption each and one marked default (`DiskRegistry`).
- **Presets** are declared by the installation: the package ships none, because the sizes
  of a site are not a matter of the library.
- **Drivers** decide where bytes land; the local one writes onto a Laravel disk, at
  `{uuid[0:2]}/{uuid}/v{version}/original.{ext}` and
  `{uuid[0:2]}/{uuid}/v{version}/{preset}.{format}` (`StoragePath`). The version is in
  the path so that a replaced file gets an address of its own instead of fighting a cache
  that was told the old one is immutable.

The models carry morph aliases (`cms_media`, `cms_media_folder`, `cms_media_usage`,
`cms_media_variant`), registered by the provider.

## Uploading

`Ewk\Cms\Media\Actions\UploadMedia::handle(UploadedFile $file, UploadOptions $options): UploadResult`
is the only way a file enters the library. There is one path on purpose: a second one
is a rule that can be walked around.

`UploadOptions` says where and on whose behalf: `disk` (the storage marked default when
it is null, an unknown name is an error), `folderId`, `uploadedBy`, `ownerType` and
`ownerId`, `allowedKinds` (a list of `FileKind` that can only narrow the configuration),
`force` (store a copy although the bytes are already here) and `name`. `UploadResult`
holds either `media`, the record that was created, or `duplicate`, the record that
already holds the same bytes; `isDuplicate()` tells them apart.

The steps, in this order:

1. **The extension of the client name** has to belong to a kind of `cms-media.kinds`, and
   that kind has to be one the caller accepts. The extension only sorts out the obvious
   and is never trusted further: `photo.php.jpg` is a `jpg` here, and the bytes decide
   the rest.
2. **The size** has to fit `max_size` of that kind. It is measured before the bytes are
   read, because deciding the type of a vector means reading the whole file and parsing
   it as a document, and a file too large to keep should never get that far.
3. **The type of the bytes**, read with `finfo` from the temporary file, has to be one
   the same kind allows, and for a picture it has to be the format the extension
   promised. `application/octet-stream` matches nothing. A vector is the one special
   case: `finfo` reports an SVG as XML, as text or as HTML depending on how the file
   opens, so the document itself is parsed and its root element has to be `svg`. A
   document that declares entities is refused before a parser is handed it. The legacy
   office formats share one container, which `finfo` reports as
   `application/x-ole-storage` or `application/vnd.ms-office`; both are in the allowlist
   of `document`.
4. **The sha256 of the bytes that arrived** is what `checksum` holds. Without `force`, a
   record with the same digest comes back as a duplicate and nothing is written. The
   search stays inside what the uploader may see: the files without an owner for the
   panel, and the files of that owner for anyone else.
5. **The work of the kind.** A picture is measured by its header first
   (`max_megapixels`, checked before any decoder sees the file), then decoded, rotated by
   its EXIF, scaled down to `max_dimension` on the long side and written out again in the
   same format, without its metadata and without anything appended after the end of the
   image. An animated GIF is decoded once to prove it is one, trimmed at its trailer and
   stored as it came. A vector goes through `enshrined/svg-sanitize` and then a second
   sweep: `script`, `foreignObject`, every `on…` handler, a `use` or `image` whose
   reference leaves the document, and a link that runs code. Video, audio and documents
   are stored as they are, under the extension their type deserves and not the one the
   name carried.
6. **The write.** `MediaDriverContract::store()` puts the prepared bytes at their path
   with `ContentType`, `CacheControl: public, max-age=31536000, immutable` and a
   `ContentDisposition` (`inline` for pictures, vectors, video, audio and PDF,
   `attachment` for other documents, the name written both as plain ASCII and as
   `filename*=UTF-8''…`, as RFC 6266 asks). No visibility is passed, because an R2 bucket
   has no ACLs.
7. **The record**, and only then, in a transaction together with the variant rows. A
   failure removes the version directory that was just written.

Every refusal of the file itself is an `Ewk\Cms\Media\Exceptions\UploadRejectedException`
with a sentence for the editor and a `status()`: `UnsupportedFileException` (415),
`FileTooLargeException` and `ImageTooLargeException` (413), `CorruptImageException`
(422). A replacement can also be refused because of who asked for it, and that one is a
`ForbiddenMediaActionException` (403). The sentences live in `lang/{ru,en}/media.php`
under the `cms-media` namespace.

`ReplaceMedia::handle(CmsMedia $media, UploadedFile $file, Actor $actor)` runs the same
checks with the kind pinned to the one of the record, writes the next version, recreates
the variants and removes the files of the old version once the record points at the new
one. The uuid, the name, the metadata, the folder, the owner and every usage stay as they
were. `MediaUploaded`, `MediaReplaced` and `MediaDeleted` are raised through the event
dispatcher, for an optimiser, a virus scanner or an audit log.

## Actions

Everything that changes a record or the tree is an action, and every one of them takes an
`Actor` and checks it itself, so an import, a console command and a seeder cannot go
around a rule a controller would hold. `Actor::admin(?int $userId)` sees the whole
library; `Actor::owner(string $type, int|string $id)` sees only the files whose
`owner_type`/`owner_id` match. Folders are shared, so arranging them, and moving files
between them, is an administrator's job.

| Action | Signature | Refuses with |
|---|---|---|
| `DeleteMedia` | `handle(CmsMedia $media, Actor $actor): void` | `MediaInUseException`, `ForbiddenMediaActionException` |
| `DeleteMedia` | `handleMany(iterable<CmsMedia> $media, Actor $actor): DeletionReport` | nothing: the report carries the refusals |
| `MoveMedia` | `handle(iterable<CmsMedia> $media, ?CmsMediaFolder $folder, Actor $actor): void` | `ForbiddenMediaActionException` |
| `RenameMedia` | `handle(CmsMedia $media, string $name, Actor $actor): CmsMedia` | `InvalidMediaNameException` (422) |
| `UpdateMediaMetadata` | `handle(CmsMedia $media, MediaMetadata $metadata, Actor $actor): CmsMedia` | `InvalidMediaLocaleException` (422) |
| `CreateFolder` | `handle(?CmsMediaFolder $parent, string $name, Actor $actor): CmsMediaFolder` | `InvalidMediaNameException`, `DuplicateFolderNameException` (409) |
| `RenameFolder` | `handle(CmsMediaFolder $folder, string $name, Actor $actor): CmsMediaFolder` | same two |
| `MoveFolder` | `handle(CmsMediaFolder $folder, ?CmsMediaFolder $target, Actor $actor): CmsMediaFolder` | `InvalidFolderMoveException` (422), `DuplicateFolderNameException` |
| `DeleteFolder` | `handle(CmsMediaFolder $folder, Actor $actor): void` | `FolderNotEmptyException` (409) |

`DeleteMedia` deletes the row inside a transaction and the files after it has been
committed: a failure on a storage then leaves files nobody points at, never a row
pointing at bytes that are gone. `handleMany` never lets one refusal stop the rest;
`DeletionReport` carries `deleted`, `inUse` and `forbidden` as lists of uuids.

## Reading the library

`Ewk\Cms\Media\Queries\MediaQuery` is an immutable builder: every method returns a copy.

```php
$page = MediaQuery::make()
    ->search('afisha')
    ->inFolder($folderId)          // or ->inFolder(null, rootOnly: true)
    ->ofKind([FileKind::IMAGE, FileKind::VECTOR])
    ->withoutAlt('ru')
    ->unused()
    ->ownedBy('organizer', 7)
    ->sort(MediaSort::NEWEST)      // NEWEST, NAME, SIZE_DESC
    ->limit(60)                    // 1 to 200, 60 by default
    ->after($cursor)
    ->get();                       // MediaPage { items, nextCursor }
```

Paging is by an opaque cursor and not by offset, and every order ends in the identifier,
so two files of the same second, name or size keep a stable place between pages. Usages
are counted and never loaded. `search` is a `LIKE` over `name`, `original_name` and the
raw json of `metadata`. `MediaQuery::find($uuid)`, `findOrFail($uuid)` and
`countsByFolder()` (the root under `MediaQuery::ROOT`) complete it.

## Where a file is used

`MediaUsages::of(CmsMedia $media): list<UsageGroup>` is the list an editor is shown
instead of the deletion they asked for. Rows are grouped by the record the registry
named, so a picture in three blocks of one page is one page and not three; a
`UsageSource` keeps the dotted paths inside each record.

The names come from `Ewk\Cms\Media\Usages\UsageLabelRegistry`, a singleton other packages
extend from their own providers:

```php
$this->app->extend(UsageLabelRegistry::class, static function (UsageLabelRegistry $registry): UsageLabelRegistry {
    $registry->register(new PageUsageLabelResolver());

    return $registry;
});
```

A `UsageLabelResolver` answers `supports(string $usableType)` and
`resolve(CmsMediaUsage $usage): ?UsageOwnerLabel`. A `UsageOwnerLabel` carries `title`,
`type` (a key such as `page` or `tour`, not a caption), `url`, `ownerType` and
`ownerId`. A usage nothing names reads as `cms_block #418` rather than disappearing. A
resolver that has to look past the row it was handed says so by implementing
`EagerLoadsUsageOwners`, and the whole listing runs inside
`UsageLabelRegistry::remember()`, so twenty usages across two pages cost three queries.

## Attachments: the pictures a record owns

There is no table of attachments: an attachment is a row of `cms_media_usages`, so a
picture of a tour gets «where is this file used» and the refusal to delete a file that
is still shown for nothing.

| Column | An attachment | A reference inside content |
|---|---|---|
| `field` | the role: `cover`, `gallery`, `logo` | the column of the record: `content` |
| `path` | the language of that role, empty when it has none | the dotted place inside the content |
| `position` | always written, `0` upwards | never written |

Reading is the trait `Ewk\Cms\Media\Concerns\HasMediaAttachments`:

```php
final class Tour extends Model
{
    use HasMediaAttachments;
}

$tour->attachedMedia('gallery');            // Collection<CmsMedia>, by position
$tour->attachedMedia('cover', 'ru');        // the Russian cover
$tour->firstAttachedMedia('cover', 'ru');   // ?CmsMedia
$tour->attachedMediaByLocale('cover');      // ['ru' => …, 'en' => …]
Tour::query()->withAttachedMedia(['cover'])->get(); // four queries for any number of tours
```

`null` for the language is the role that has no language. It is never a fallback for
«the Russian one»: which language to show instead is a decision of the application. A
deleted record lets its rows go; a soft deleted one keeps them.

Writing is the singleton `Ewk\Cms\Media\Services\MediaAttachments`: `replace()`,
`append()`, `detach()`, `reorder()` and `release()`, each scoped to one owner, one role
and one language. `MediaQuery::attachedTo($owner, $role)` lists the files one record
owns, and `unused()` counts attachments as usages.

## The API resource

`Ewk\Cms\Media\Http\Resources\MediaResource` hands out `id` (the uuid), `url`, `name`,
`kind`, `mime`, `size`, `width`, `height`, `alt`, `placeholder`, `variants` and
`pending`; never the disk, the path or the checksum. `withOverride(?array $metadata,
string $locale, string $fallbackLocale)` takes the metadata stored beside a reference:
`alt` is the override in the language asked for, then the override in the default
language, then the record in both. `withAlt(string $alt)` is for a value already
resolved.

## The admin in MoonShine

### The «Медиа» section

`Ewk\Cms\Media\MoonShine\Pages\MediaLibraryPage` (alias `cms-media`) is a page and not a
crud resource: a library is a grid of pictures an editor recognises by sight. It has
three columns, one below 1024px:

- **the folders** on the left, rooted in «Все файлы», with counts, rename in place, a
  `…` menu of rename, move and delete (only while the folder is empty), and tiles dragged
  out of the grid land in the folder they are dropped on;
- **the grid** in the middle, with a search, chips of the kinds, «Без описания» and «Не
  используются», a sort, tiles or a list, the storage picker while more than one disk is
  configured, and «Показать ещё» that follows the cursor. A picture shows the preset of
  `moonshine-media.thumbnail_preset`, a vector shows itself, everything else the glyph of
  its kind;
- **the details** on the right: a big preview, the name and the description edited in
  place, «Где стоит» with a link into the form of the record that holds the file, and the
  facts in words. No uuid, no disk, no path, no MIME string, no checksum.

Uploading is immediate: the whole page is a dropzone, the queue shows a bar per file
(three at a time), an error in words with «Повторить», and for a file the library already
holds «Использовать существующий» or «Загрузить копию».

### The endpoints

All of them sit inside the route group of the panel, behind its middleware and its
authentication, under `cms/media` and named `moonshine.cms.media.*`. Reading is
`Http\Controllers\MediaLibraryController`, writing is
`Http\Controllers\MediaLibraryCommandsController`, and every write takes a POST.

| Route | Answer |
|---|---|
| `GET items` | `{items: [...], next_cursor}` by `q`, `folder`, `kind` or `kinds[]`, `without_alt`, `unused`, `sort`, `cursor` |
| `GET folders` | the tree with the counts |
| `GET picker` | the body of the picker dialog as HTML, by `kinds[]`, `multiple`, `folder`, `selected`, `target`, `scope` |
| `GET {uuid}` | the details of one file |
| `POST upload` | multipart, one file, `folder`, `disk`, `force`, `allowed_kinds[]`; `{media}` or `{duplicate}`; throttled at 60 a minute |
| `POST {uuid}` | `name`, `alt[]`, `caption[]` |
| `POST {uuid}/replace` | multipart; throttled at 60 a minute |
| `POST move`, `POST delete` | `uuids[]`, `folder` |
| `POST folders`, `POST folders/{id}`, `POST folders/{id}/move`, `POST folders/{id}/delete` | the tree again |

`allowed_kinds[]` narrows the allowlist and never widens it. A folder identifier that
names no folder is a 404 and never «no folder chosen». Every mutation goes through an
action of the library with `Actor::admin()`; a refusal's sentence and `status()` become
the answer, and deleting a file in use answers 409 with the places it stands in.

### The picker dialog

Every media field opens one dialog per document: a `Modal` named `cms-media-picker`,
drawn by `Library\PickerModal::once()` for the first field of the page that is not inside
a repeater row. A screen whose only pictures live inside a repeater calls
`PickerModal::once()` itself, after its fields. The body is the section again — the same
partials and the same `cmsMediaGridCore` — without the details panel and without
anything that renames, moves or deletes.

Choosing dispatches one event on the window:

```js
new CustomEvent('cms-media-pick', {detail: {target, items: [
    {id, url, thumb_url, name, kind, size_human, width, height},
]}})
```

`target` is the key of the field that opened the dialog, so several pickers on one page
never pick for one another. The folder an editor was last in is remembered per place in
`localStorage` (`cms-media-picker:folder:<scope>`).

### The picture field

`Ewk\Cms\Media\MoonShine\Fields\MediaPicker` is one picture as one field, stored as a
reference plus the description of this one place:

```php
MediaPicker::make('Логотип', 'logo')->kinds(['image', 'vector']);
MediaPicker::make('Картинка для соцсетей', 'og_image')->withoutDescription();
```

```json
{"id": "9f3c…", "metadata": {"alt": {"ru": "Афиша", "en": "Poster"}}}
```

It draws the thumbnail, the name and the readable size of what is stored, and offers
«Выбрать из библиотеки» and «Загрузить с компьютера». There is no deferred upload: a file
is a record of the library the moment it is chosen. `->kinds()` narrows the grid of the
dialog, the extensions the file dialog offers and the `allowed_kinds[]` of the upload; the
default is a picture or an icon. `alt` is one input per language of the
`LanguageCatalog`, empty meaning «inherit the description of the file».
`->withoutDescription()` takes that block away for a place whose writer stores the
reference alone. Inside a MoonShine `Json` repeater every input carries the marks the
repeater renames it by (`Fields\CompositeValueField`).

### The attachments field

`Ewk\Cms\Media\MoonShine\Fields\MediaAttachmentsField` is one role of a record as one
field; the resource carries the concern that writes it:

```php
use Ewk\Cms\Media\MoonShine\Concerns\InteractsWithMediaAttachments;
use Ewk\Cms\Media\MoonShine\Fields\MediaAttachmentsField;

final class VenueResource extends ModelResource
{
    use InteractsWithMediaAttachments;
}

// the form page
MediaAttachmentsField::make('Логотип', 'logo'),
MediaAttachmentsField::make('Галерея', 'gallery')->multiple()->max(6),
MediaAttachmentsField::make('Обложка', 'cover')->locale('ru')->kinds(['image']),

// its rules
...$this->getResource()->mediaAttachmentRules('cover', 'ru', required: true),
...$this->getResource()->mediaAttachmentRules('gallery', max: 6),
```

The model has to use `HasMediaAttachments`. Every file is one hidden input
`media_attachments[<role>][<language or _>][]`, in the order shown, plus one marker
`media_attachments_present[<role>][<language or _>]`, so a cleared gallery and a role no
form carries mean different things. The concern overrides `afterSave()` and rewrites
every role the form carried through `MediaAttachments::replace()`, reading the roles,
languages and kinds off the fields MoonShine has just applied rather than off the
request. A resource that declares `afterSave()` itself, or replaces `save()`, calls
`saveMediaAttachments($item->getOriginal(), request()->all(), $fields)` by hand.

A field given a language marks its wrapper `data-cms-locale` and hides itself unless the
Alpine `locale` of an enclosing language switch matches, so a form without such a switch
shows every language at once.

### The listing column

```php
use Ewk\Cms\Media\MoonShine\Fields\AttachmentPreview;

AttachmentPreview::make('Площадка', 'logo')->withTitle('name'),
AttachmentPreview::make('Тур', 'cover', fn (): string => app()->getLocale())->withTitle('name'),
```

The thumbnail sits left of the title in one cell. Load the pictures with
`withAttachedMedia(['logo'])` in `modifyQueryBuilder()` and the listing costs four
queries whatever the number of rows.

### Assets

The package ships no build step. `Library\MediaAssets::inline()` inlines
`resources/css/cms-media.css` and `resources/js/cms-media.js`; the section and every
field ask for it, and the asset manager keeps one copy per page. `AttachmentPreview`
inlines its own few lines only.

## Extension points

| What | How |
|---|---|
| The content languages | Bind `Ewk\Cms\Media\Localization\LanguageCatalog` (`locales()`, `defaultLocale()`). The default, `ConfigLanguageCatalog`, reads `cms-media.locales` and `cms-media.default_locale` and is bound with `singletonIf`, so a binding of the application wins whichever provider runs first. |
| The names of usages | Extend `Ewk\Cms\Media\Usages\UsageLabelRegistry` with a `UsageLabelResolver`, see above. |
| Where «used in» links to, and what a kind of usage is called | Extend `Ewk\Cms\Media\MoonShine\Library\UsageLinkRegistry`, see below. |
| Captions of the usage kinds of the application | Lay `usage_types.<type>` over the dictionary in `lang/vendor/moonshine-media/{ru,en}/library.php`. |
| The storage driver | Bind `Ewk\Cms\Media\Contracts\MediaDriverContract`. |
| Video frames | `Ewk\Cms\Media\Contracts\VideoFramesContract`, bound to `FfmpegVideoFrames`, used when `cms-media.video.frames` is on. |
| Reacting to changes | Listen to `MediaUploaded`, `MediaReplaced`, `MediaDeleted`. |

### The usage link registry

The core hands the admin the morph alias and the key of a record and never an address.
`UsageLinkRegistry` is where a package that owns records says which MoonShine resource
edits them and what their kind is called; it is a singleton of the admin provider, and
packages extend it from their own:

```php
use Ewk\Cms\Media\MoonShine\Library\UsageLinkRegistry;

$this->app->extend(UsageLinkRegistry::class, static fn (UsageLinkRegistry $links): UsageLinkRegistry => $links
    ->resource(Page::MORPH_ALIAS, PageResource::class)
    // A closure is asked when a link is built, for a resource that is configurable.
    ->resource(Header::MORPH_ALIAS, static fn (): string => config('my-cms.header_resource'))
    // A translation key, or a closure that answers with the caption.
    ->caption('page', 'my-cms::usages.page'));
```

A link is the `url` the resolver brought, if any; otherwise the form page of the
registered resource; otherwise none, and the line stays plain text. A caption is the
registered one, then `moonshine-media::library.usage_types.<type>`, then «Запись» /
«Record». With nothing registered, the library still lists every usage by name.

## Configuration

`config/cms-media.php`:

| Key | Default | What it is |
|---|---|---|
| `cms-media.disks` | one entry, `CMS_MEDIA_DISK` or `public` | The storages the editor may upload to: `{disk, label, default}`. |
| `cms-media.kinds` | the five kinds | Per kind: `extensions`, `mimes` and `max_size` in bytes. The allowlist of the library. |
| `cms-media.image.driver` | `CMS_MEDIA_IMAGE_DRIVER`, else `gd` | `gd` or `imagick`. The older `cms-media.image_driver` is still read when this one is absent. |
| `cms-media.image.max_dimension` | `2560` | The long side of the stored original. |
| `cms-media.image.max_megapixels` | `30` | Checked against the header before any decoder sees the file. |
| `cms-media.image.quality` | `90` | The quality the original is re-encoded with. |
| `cms-media.video.*` | off | `frames`, `ffmpeg`, `ffprobe`, `frame_at`, `timeout`: frames of a video as its variants. |
| `cms-media.queue` | `CMS_MEDIA_QUEUE` | The queue the conversions of a queued preset go to. |
| `cms-media.default_locale` | `null` → `app.locale`, then `en` | The default content language of `ConfigLanguageCatalog`. |
| `cms-media.locales` | `null` → the default language alone | The content languages a description of a file is written in, e.g. `['ru', 'en']`. An application with a language source of its own binds `LanguageCatalog` instead. |
| `cms-media.presets` | `[]` | Named conversions, `{width, height, fit, format, quality, queued, kinds}`; `fit` is `cover`, `contain` or `scale-down`. |

`config/moonshine-media.php`:

| Key | Default | What it is |
|---|---|---|
| `moonshine-media.thumbnail_preset` | `thumb` | Which preset the panel draws as a thumbnail: the tiles of the library, the card of a media field, the listing column. A record without that variant falls back to the nearest ready one. |
| `moonshine-media.display_timezone` | `null` | The zone the panel shows instants in, such as the upload date. `null` is `app.timezone`. |

`php artisan cms-media:variants {--kind=}` queues the variants the library lacks — of a
preset declared later, of a video uploaded before frames were cut, and the failed ones.

## Testing

```bash
composer install
composer test        # pest
composer phpstan     # level max
composer format:dry  # php-cs-fixer
```

The suite runs on SQLite in memory with foreign keys enforced. The one test that cuts a
real video frame is skipped when `ffmpeg` and `ffprobe` are not installed.

## License

MIT, see [LICENSE](LICENSE).
