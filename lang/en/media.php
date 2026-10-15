<?php

declare(strict_types=1);

return [
    'in_use' => 'Media :uuid is used in :count place(s) and cannot be deleted. Remove it from the content and try again.',

    /*
     * Upload refusals. An editor reads these, so they carry no technical word
     * and each one ends with something that can be done about it.
     */
    'unsupported_type' => 'This format is not supported. Try one of: :allowed',
    'type_mismatch' => 'The contents of the file do not match its extension. Upload a real file of that format.',
    'too_large' => 'The file is too large (:size MB). The limit is :max MB.',
    'image_too_large' => 'The picture is too big: :megapixels megapixels. The limit is :max.',
    'corrupt_image' => 'The picture could not be opened. The file may be damaged.',
    'svg_rejected' => 'This SVG could not be cleaned up safely. Export it from your editor again.',
    'avif_unsupported' => 'AVIF is not supported here yet. Save the picture as JPG, PNG or WebP.',
    'replace_kind_mismatch' => 'A file can only be replaced with a file of the same type.',

    /*
     * The decimal separator of the numbers in the sentences above. Internal:
     * an editor never sees this key.
     */
    'number_decimal' => '.',

    // Actions: what the library refuses when a file or a folder is
    // changed. Every one of them reaches an editor, so none of them names a
    // column or a class.
    'forbidden' => 'You are not allowed to perform this action.',
    'invalid_name' => 'The name must not be empty and must not be longer than :max characters.',
    'folder_not_empty' => 'The folder :name is not empty. Move its files and subfolders out first.',
    'duplicate_folder' => 'A folder named :name already exists here. Choose another name.',
    'invalid_folder_move' => 'The folder :name cannot be moved into itself or into a folder inside it.',
    'invalid_locale' => 'The language code :locale is not valid.',
];
