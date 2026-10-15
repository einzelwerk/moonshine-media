<?php

declare(strict_types=1);

return [
    'in_use' => 'Файл :uuid используется в записях (:count) и не может быть удалён. Уберите его из содержимого и повторите.',

    /*
     * Upload refusals. An editor reads these, so they carry no technical word
     * — no MIME type, no storage, no path — and each one ends with something
     * that can be done about it.
     */
    'unsupported_type' => 'Такой формат не поддерживается. Подойдут: :allowed',
    'type_mismatch' => 'Содержимое файла не совпадает с его расширением. Загрузите файл нужного формата.',
    'too_large' => 'Файл слишком большой (:size МБ). Максимум :max МБ.',
    'image_too_large' => 'Изображение слишком большое: :megapixels мегапикселей. Максимум :max.',
    'corrupt_image' => 'Не удалось открыть изображение. Возможно, файл повреждён.',
    'svg_rejected' => 'Этот SVG не удалось безопасно обработать. Сохраните его из редактора заново.',
    'avif_unsupported' => 'Формат AVIF пока не поддерживается. Сохраните картинку в JPG, PNG или WebP.',
    'replace_kind_mismatch' => 'Заменить файл можно только файлом того же типа.',

    /*
     * The decimal separator of the numbers in the sentences above: a comma in
     * Russian, a point in English. Internal: an editor never sees this key.
     */
    'number_decimal' => ',',

    // Actions: what the library refuses when a file or a folder is
    // changed. Every one of them reaches an editor, so none of them names a
    // column or a class.
    'forbidden' => 'Недостаточно прав для этого действия.',
    'invalid_name' => 'Название не может быть пустым и длиннее :max символов.',
    'folder_not_empty' => 'Папка «:name» не пустая. Сначала уберите из неё файлы и вложенные папки.',
    'duplicate_folder' => 'Папка «:name» здесь уже есть. Выберите другое название.',
    'invalid_folder_move' => 'Папку «:name» нельзя перенести саму в себя или внутрь себя.',
    'invalid_locale' => 'Код языка «:locale» указан неверно.',
];
