<?php

declare(strict_types=1);

/*
 * The «Media» section. Every line here is read by an editor, so none of them
 * names an address, a content type or a storage other than by its caption.
 * Sizes and dates are formatted on the server, see
 * \Ewk\Cms\Media\MoonShine\Library\HumanSize.
 */
return [
    'title' => 'Media',

    'decimal' => '.',
    'units' => ['B', 'KB', 'MB', 'GB'],

    'search' => 'Search by name',
    'search_clear' => 'Clear the search',

    'kinds' => [
        'all' => 'All',
        'image' => 'Photos',
        'vector' => 'Icons',
        'video' => 'Video',
        'audio' => 'Audio',
        'document' => 'Documents',
    ],

    'flags' => [
        'without_alt' => 'No description',
        'unused' => 'Unused',
    ],

    'sorts' => [
        'label' => 'How the files are ordered',
        'newest' => 'Newest first',
        'name' => 'By name',
        'size_desc' => 'By size',
    ],

    'view' => [
        'grid' => 'Tiles',
        'list' => 'List',
    ],

    'storage' => 'Storage',
    'upload' => 'Upload',
    'more' => 'Show more',
    'loading' => 'Loading',

    'empty' => [
        'title' => 'Nothing here yet',
        'hint' => 'Drop files here or press «Upload».',
        'search_title' => 'Nothing found',
        'search_hint' => 'Try another word or drop the filters.',
    ],

    'badges' => [
        'no_alt' => 'no description',
        'unused' => 'unused',
    ],

    'columns' => [
        'name' => 'Name',
        'kind' => 'Type',
        'size' => 'Size',
        'date' => 'Uploaded',
        'usages' => 'Used in',
    ],

    'folders' => [
        'root' => 'All files',
        'create' => 'New folder',
        'name' => 'Folder name',
        'save' => 'Create',
        'cancel' => 'Cancel',
        'rename' => 'Rename',
        'move' => 'Move',
        'delete' => 'Delete',
        'actions' => 'What to do with the folder',
        'not_empty' => 'Take the files and the folders out of it first',
        'expand' => 'Expand or collapse',
        'move_title' => 'Where to move the folder',
    ],

    'selection' => [
        'selected' => 'Selected: :count',
        'clear' => 'Clear the selection',
        'move' => 'Move',
        'delete' => 'Delete',
        'select' => 'Select the file',
    ],

    'details' => [
        'title' => 'About the file',
        'close' => 'Close',
        'name' => 'Name',
        'alt' => 'Description',
        'alt_hint' => 'A short sentence about what is on the picture. It is read aloud to blind visitors.',
        'caption' => 'Caption',
        'folder' => 'Folder',
        'used_in' => 'Used in',
        'not_used' => 'Not used anywhere yet',
        'replace' => 'Replace the file',
        'download' => 'Download',
        'open' => 'Open',
        'size' => 'Size',
        'dimensions' => 'Dimensions',
        'format' => 'Format',
        'uploaded' => 'Uploaded',
        'uploaded_by' => 'Uploaded by',
        'storage' => 'Storage',
        'preview_missing' => 'There is no preview for this file',
    ],

    'picker' => [
        'title' => 'Choose a file',
        'submit' => 'Choose',
        'cancel' => 'Cancel',
        'open_library' => 'Open the media library',
        'empty_hint' => 'Drop files here or press «Upload».',
    ],

    'move' => [
        'title' => 'Where to move',
        'submit' => 'Move',
        'cancel' => 'Cancel',
    ],

    'confirm' => [
        'delete_title' => 'Delete',
        'delete_one' => 'Delete the file «:name»? It cannot be brought back.',
        'delete_many' => 'Delete the selected files (:count)? They cannot be brought back.',
        'delete' => 'Delete',
        'cancel' => 'Cancel',
        'in_use_title' => 'The file is used on pages',
        'in_use' => 'Take it off the places below first, then it can be deleted.',
    ],

    'uploader' => [
        'title' => 'Upload',
        'progress' => ':done of :total done',
        'hide' => 'Hide',
        'queued' => 'Waiting',
        'uploading' => 'Uploading',
        'done' => 'Done',
        'failed' => 'Failed',
        'retry' => 'Try again',
        'remove' => 'Take off the list',
        'duplicate' => 'This file is already here',
        'use_existing' => 'Use the existing one',
        'upload_copy' => 'Upload a copy',
        'drop_root' => 'Drop to upload into «All files»',
        'drop_folder' => 'Drop to upload into «:folder»',
        'kind_line' => ':kind: :formats. No larger than :size',
    ],

    'messages' => [
        'saved' => 'Saved',
        'replaced' => 'The file is replaced',
        'moved' => '{1} The file is moved|[2,*] Moved: :count',
        'deleted' => '{1} The file is deleted|[2,*] Deleted: :count',
        'deleted_partial' => '{1} Deleted: :deleted. :count file is used on pages and stayed.|[2,*] Deleted: :deleted. :count files are used on pages and stayed.',
        'deleted_none' => 'Nothing was deleted: the selected files are used on pages.',
        'folder_created' => 'The folder is created',
        'folder_renamed' => 'The folder is renamed',
        'folder_moved' => 'The folder is moved',
        'folder_deleted' => 'The folder is deleted',
        'request_failed' => 'The request did not go through, try again',
        'too_large' => 'The file is too large and never reached the server',
        'no_file' => 'The file never reached the server, try again',
        'not_found' => 'The file is gone: it may have been deleted already',
        'folder_not_found' => 'The folder is gone: it may have been deleted already',
        'nothing_chosen' => 'Choose the files first',
    ],

    'places' => '{1} :count place|[2,*] :count places',

    'usage_types' => [
        'unknown' => 'Record',
    ],
];
