<?php

declare(strict_types=1);

return [
    'empty' => 'Drop a file here or choose one from the library',
    'drop' => 'Release the file',
    // The two buttons are named by where the picture comes from and not by
    // what they do: «Replace» and «Upload» side by side read as the same
    // action, because both of them replace the picture.
    'choose' => 'Choose from the library',
    'upload' => 'Upload from the computer',
    'clear' => 'Remove',
    'uploading' => 'Uploading the file',
    'missing' => 'The file is no longer in the library, choose another one',
    'no_preview' => 'File without a preview',

    'duplicate' => 'This file is already in the library.',
    'use_existing' => 'Use the existing one',
    'upload_copy' => 'Upload a copy',

    'alt' => 'Description for this place',
    'alt_hint' => 'Leave it empty and the picture takes its description from the library.',

    // The pictures a record owns: the cover of a tour, the logo of a venue, a
    // gallery. They are chosen from the same library, and their order is set
    // by dragging.
    'attachments' => [
        'empty' => 'No file chosen yet',
        'empty_multiple' => 'No files chosen yet',
        'add' => 'Choose from the library',
        'remove' => 'Remove',
        'remove_named' => 'Remove :name',
        'reorder' => 'Drag a card to change the order',
        'limit' => 'No more than :max files fit here',
        'no_preview' => 'File without a preview',
    ],
];
