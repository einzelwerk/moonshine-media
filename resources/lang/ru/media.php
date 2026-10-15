<?php

declare(strict_types=1);

/*
 * The field of a picture. An editor reads these, so none of them names a
 * uuid, a type of content or a storage. The words of the library itself live
 * in library.php, the refusals of an upload in the `cms-media` namespace.
 */
return [
    'empty' => 'Перетащите файл сюда или выберите из библиотеки',
    'drop' => 'Отпустите файл',
    // The two buttons are named by where the picture comes from and not by
    // what they do: «Заменить» and «Загрузить» side by side read as the same
    // action, because both of them replace the picture. The captions are the
    // same in an empty field and in a filled one.
    'choose' => 'Выбрать из библиотеки',
    'upload' => 'Загрузить с компьютера',
    'clear' => 'Убрать',
    'uploading' => 'Загружаем файл',
    'missing' => 'Файл не найден в медиатеке, выберите другой',
    'no_preview' => 'Файл без превью',

    'duplicate' => 'Такой файл уже есть в библиотеке.',
    'use_existing' => 'Использовать существующий',
    'upload_copy' => 'Загрузить копию',

    'alt' => 'Описание для этого места',
    'alt_hint' => 'Оставьте пустым, и картинка возьмёт своё описание из медиатеки.',

    // The pictures a record owns: the cover of a tour, the logo of a venue, a
    // gallery. They are chosen from the same library, and their order is set
    // by dragging.
    'attachments' => [
        'empty' => 'Файл пока не выбран',
        'empty_multiple' => 'Файлы пока не выбраны',
        'add' => 'Выбрать из библиотеки',
        'remove' => 'Убрать',
        'remove_named' => 'Убрать :name',
        'reorder' => 'Перетащите карточку, чтобы поменять порядок',
        'limit' => 'Сюда помещается не больше :max файлов',
        'no_preview' => 'Файл без превью',
    ],
];
