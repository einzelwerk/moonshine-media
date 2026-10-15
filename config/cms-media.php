<?php

declare(strict_types=1);

return [
    /*
     * The storages the editor may upload to, in the order they are offered.
     * The key of an entry is a Laravel disk; `label` is what a person reads,
     * so it carries no technical word. One entry is marked `default`; with
     * nothing marked the first one is used, and the picker of a storage is
     * hidden entirely while there is only one.
     */
    'disks' => [
        ['disk' => env('CMS_MEDIA_DISK', 'public'), 'label' => 'Main storage', 'default' => true],
    ],

    /*
     * The allowlist of the library. Nothing here ever comes from a request:
     * the extension of a client name only sorts out the obvious, the MIME type
     * is read from the bytes with `finfo`, and the extension the file is
     * finally stored under is the one the server picked.
     *
     * `max_size` is in bytes and is the last word on the size of an upload,
     * whatever the web server and PHP happen to allow.
     */
    'kinds' => [
        'image' => [
            'extensions' => ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif'],
            'mimes' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif'],
            'max_size' => 20 * 1024 * 1024,
        ],
        'vector' => [
            'extensions' => ['svg'],
            /*
             * Only the canonical type is listed. An SVG is text, and what
             * `finfo` actually reports depends on the magic database of the
             * image: `image/svg+xml` when the file opens with an XML
             * declaration, `image/svg`, `text/xml` or even `text/plain` when
             * it opens with the `<svg>` element. Widening the allowlist would
             * let any text file in, so the upload pipeline treats a vector as
             * a special case and decides by parsing the document instead.
             */
            'mimes' => ['image/svg+xml'],
            'max_size' => 2 * 1024 * 1024,
        ],
        'video' => [
            'extensions' => ['mp4', 'webm'],
            'mimes' => ['video/mp4', 'video/webm'],
            'max_size' => 100 * 1024 * 1024,
        ],
        'audio' => [
            'extensions' => ['mp3', 'ogg', 'wav', 'm4a'],
            'mimes' => ['audio/mpeg', 'audio/ogg', 'audio/wav', 'audio/x-wav', 'audio/mp4', 'audio/x-m4a'],
            'max_size' => 50 * 1024 * 1024,
        ],
        'document' => [
            'extensions' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'],
            /*
             * The last two are what `finfo` reports for the legacy office
             * formats: `.doc`, `.xls` and `.ppt` are all one container, and
             * the magic database sees the container and not the application.
             * They are listed so that a real Word file is not refused; which
             * of the three it is stays with the extension of the name, which
             * is the only thing that knows.
             */
            'mimes' => [
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/vnd.ms-powerpoint',
                'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'application/x-ole-storage',
                'application/vnd.ms-office',
            ],
            'max_size' => 25 * 1024 * 1024,
        ],
    ],

    /*
     * How a raster original is prepared when it is uploaded. The bytes that
     * arrived are not kept: a second copy doubles the storage and nothing
     * reads it. The stored original is the re-encoded one, rotated by its
     * EXIF, stripped of metadata and scaled down to `max_dimension` on the
     * long side. `max_megapixels` is checked against the header before any
     * decoder sees the file.
     */
    'image' => [
        'driver' => env('CMS_MEDIA_IMAGE_DRIVER', 'gd'),
        'max_dimension' => 2560,
        /*
         * Thirty megapixels is about what a camera of today produces and
         * about as much as a decoder may be given: GD holds a picture as four
         * bytes a pixel, so thirty of them are some hundred and twenty
         * megabytes of canvas, and a PHP process is usually allowed five.
         */
        'max_megapixels' => 30,
        'quality' => 90,
    ],

    /*
     * Frames of a video, see `Ewk\Cms\Media\Video\FfmpegVideoFrames`. Off by
     * default, because it needs `ffmpeg` and `ffprobe` on the server; turned
     * on, a video gets the variants of every preset that takes it, made of
     * the frame at `frame_at` seconds (the first one for a shorter clip), and
     * the size it is shown at. Without the tools a video simply stays without
     * a preview. A whole video is never decoded, so `timeout` is generous.
     *
     * The application config replaces this array as a whole when it declares
     * one, so every key is also read with this default.
     */
    'video' => [
        'frames' => false,
        'ffmpeg' => 'ffmpeg',
        'ffprobe' => 'ffprobe',
        'frame_at' => 1.0,
        'timeout' => 60,
    ],

    /*
     * The queue the conversions of a queued preset go to. `null` keeps them on
     * the default queue of the connection; an application whose workers keep a
     * lane for slow background work points them there.
     */
    'queue' => env('CMS_MEDIA_QUEUE'),

    /*
     * The content languages a description of a file is written in, read by
     * the default `Ewk\Cms\Media\Localization\ConfigLanguageCatalog`. `null`
     * for `default_locale` is `app.locale`; `null` for `locales` is the default
     * language alone. An application that keeps its languages elsewhere binds
     * `LanguageCatalog` itself instead of filling these.
     */
    'locales' => null,
    'default_locale' => null,

    /*
     * Named conversion presets. Empty by default — the consuming application
     * declares its own, e.g.:
     *
     * 'thumb' => ['width' => 400, 'height' => 400, 'fit' => 'cover', 'format' => 'webp', 'quality' => 80, 'queued' => true],
     *
     * `fit` is `cover`, `contain` or `scale-down` (the whole picture inside
     * the box, never enlarged, nothing added). `kinds` limits a preset to some
     * kinds of file, e.g. ['video'] for a poster; without it a preset is made
     * for every kind that has variants.
     */
    'presets' => [],
];
