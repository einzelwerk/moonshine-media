# Changelog

All notable changes to `ewk/moonshine-media` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and the project adheres to [Semantic Versioning](https://semver.org/). Until
`1.0.0` a minor version may contain breaking changes.

## 0.1.0 — 2026-10-16

First public release: a media library for Laravel 13 with an admin for
MoonShine 4.

### Library (`Ewk\Cms\Media`)

- Records of the library (`cms_media`), a virtual tree of folders, variants per
  named preset and the usages of every file, with morph aliases for all four
  models.
- One upload pipeline for uploads and replacements: an allowlist of kinds,
  extensions, MIME types and sizes from configuration; the type read from the
  bytes; pictures re-encoded without their metadata, SVG sanitised; duplicates
  found by the digest of the bytes; versioned, immutable addresses.
- Actions for everything that changes a file or a folder, each checking an
  `Actor`; deletion refused while a file is in use, with a report for a
  selection.
- `MediaQuery` with search, filters and cursor paging; `MediaUsages` with a
  `UsageLabelRegistry` other packages extend.
- The pictures a record owns: `HasMediaAttachments` to read them, with a scope
  that eager loads a whole listing, and `MediaAttachments` to write them.
- `MediaResource` for an API, which never exposes a disk, a path or a checksum.
- Video frames through `ffmpeg`, off by default; the `cms-media:variants`
  command for variants a library lacks.
- `LanguageCatalog` of the content languages, with a default that reads
  `cms-media.locales` and `cms-media.default_locale` (falling back to
  `app.locale`).

### Admin (`Ewk\Cms\Media\MoonShine`)

- The «Медиа» section: folders, grid, details, bulk move and delete, a
  dropzone with an upload queue.
- One picker dialog per page, shared by every media field.
- `MediaPicker`, `MediaAttachmentsField` with the `InteractsWithMediaAttachments`
  resource concern, and the `AttachmentPreview` listing column.
- `UsageLinkRegistry`, where packages that own records register the resource
  that edits them and the caption of their usages.
- Russian and English translations; `moonshine-media.thumbnail_preset` and
  `moonshine-media.display_timezone`.
