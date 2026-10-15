<?php

declare(strict_types=1);

/*
 * The «Медиа» section. Every line here is read by an editor, so none of them
 * names an address, a content type or a storage other than by its caption.
 * Sizes and dates are formatted on the server, see
 * \Ewk\Cms\Media\MoonShine\Library\HumanSize.
 */
return [
    'title' => 'Медиа',

    /*
     * The decimal separator and the units of a size. The server puts them in
     * and not the browser: only the server knows the language of the panel.
     */
    'decimal' => ',',
    'units' => ['Б', 'КБ', 'МБ', 'ГБ'],

    'search' => 'Поиск по названию',
    'search_clear' => 'Очистить поиск',

    'kinds' => [
        'all' => 'Все',
        'image' => 'Фото',
        'vector' => 'Иконки',
        'video' => 'Видео',
        'audio' => 'Аудио',
        'document' => 'Документы',
    ],

    'flags' => [
        'without_alt' => 'Без описания',
        'unused' => 'Не используются',
    ],

    'sorts' => [
        'label' => 'Как расставить файлы',
        'newest' => 'Сначала новые',
        'name' => 'По имени',
        'size_desc' => 'По размеру',
    ],

    'view' => [
        'grid' => 'Плитками',
        'list' => 'Списком',
    ],

    'storage' => 'Хранилище',
    'upload' => 'Загрузить',
    'more' => 'Показать ещё',
    'loading' => 'Загружаем',

    'empty' => [
        'title' => 'Здесь пока пусто',
        'hint' => 'Перетащите файлы сюда или нажмите «Загрузить».',
        'search_title' => 'Ничего не нашлось',
        'search_hint' => 'Попробуйте другое слово или снимите фильтры.',
    ],

    'badges' => [
        'no_alt' => 'нет описания',
        'unused' => 'не используется',
    ],

    'columns' => [
        'name' => 'Название',
        'kind' => 'Тип',
        'size' => 'Размер',
        'date' => 'Загружен',
        'usages' => 'Где используется',
    ],

    'folders' => [
        'root' => 'Все файлы',
        'create' => 'Новая папка',
        'name' => 'Название папки',
        'save' => 'Создать',
        'cancel' => 'Отмена',
        'rename' => 'Переименовать',
        'move' => 'Переместить',
        'delete' => 'Удалить',
        'actions' => 'Что сделать с папкой',
        'not_empty' => 'Сначала уберите из папки файлы и вложенные папки',
        'expand' => 'Раскрыть или свернуть',
        'move_title' => 'Куда перенести папку',
    ],

    'selection' => [
        'selected' => 'Выбрано: :count',
        'clear' => 'Снять выделение',
        'move' => 'Переместить',
        'delete' => 'Удалить',
        'select' => 'Выбрать файл',
    ],

    'details' => [
        'title' => 'О файле',
        'close' => 'Закрыть',
        'name' => 'Название',
        'alt' => 'Описание',
        'alt_hint' => 'Короткая фраза о том, что на картинке. Её читают вслух незрячим посетителям.',
        'caption' => 'Подпись под файлом',
        'folder' => 'Папка',
        'used_in' => 'Где используется',
        'not_used' => 'Пока нигде не используется',
        'replace' => 'Заменить файл',
        'download' => 'Скачать',
        'open' => 'Открыть',
        'size' => 'Размер',
        'dimensions' => 'Размеры',
        'format' => 'Формат',
        'uploaded' => 'Загружено',
        'uploaded_by' => 'Загрузил',
        'storage' => 'Хранилище',
        'preview_missing' => 'Для этого файла превью нет',
    ],

    'picker' => [
        'title' => 'Выбор файла',
        'submit' => 'Выбрать',
        'cancel' => 'Отмена',
        'open_library' => 'Открыть медиатеку',
        'empty_hint' => 'Перетащите файлы сюда или нажмите «Загрузить».',
    ],

    'move' => [
        'title' => 'Куда перенести',
        'submit' => 'Перенести',
        'cancel' => 'Отмена',
    ],

    'confirm' => [
        'delete_title' => 'Удаление',
        'delete_one' => 'Удалить файл «:name»? Вернуть его будет нельзя.',
        'delete_many' => 'Удалить выбранные файлы (:count)? Вернуть их будет нельзя.',
        'delete' => 'Удалить',
        'cancel' => 'Отмена',
        'in_use_title' => 'Файл используется',
        'in_use' => 'Сначала уберите файл оттуда, где он используется, и тогда его можно будет удалить.',
    ],

    'uploader' => [
        'title' => 'Загрузка',
        'progress' => 'Готово :done из :total',
        'hide' => 'Скрыть',
        'queued' => 'В очереди',
        'uploading' => 'Загружается',
        'done' => 'Готово',
        'failed' => 'Не получилось',
        'retry' => 'Повторить',
        'remove' => 'Убрать из списка',
        'duplicate' => 'Такой файл уже есть',
        'use_existing' => 'Использовать существующий',
        'upload_copy' => 'Загрузить копию',
        'drop_root' => 'Отпустите, чтобы загрузить во «Все файлы»',
        'drop_folder' => 'Отпустите, чтобы загрузить в папку «:folder»',
        'kind_line' => ':kind: :formats. Не больше :size',
    ],

    'messages' => [
        'saved' => 'Сохранено',
        'replaced' => 'Файл заменён',
        'moved' => '{1} Файл перенесён|[2,4] Перенесено файла: :count|[5,*] Перенесено файлов: :count',
        'deleted' => '{1} Файл удалён|[2,4] Удалено файла: :count|[5,*] Удалено файлов: :count',
        'deleted_partial' => '{1} Удалено: :deleted. :count файл используется и остался.|[2,4] Удалено: :deleted. :count файла используются и остались.|[5,*] Удалено: :deleted. :count файлов используются и остались.',
        'deleted_none' => 'Ничего не удалено: выбранные файлы используются.',
        'folder_created' => 'Папка создана',
        'folder_renamed' => 'Папка переименована',
        'folder_moved' => 'Папка перенесена',
        'folder_deleted' => 'Папка удалена',
        'request_failed' => 'Запрос не прошёл, попробуйте ещё раз',
        'too_large' => 'Файл слишком большой и не дошёл до сервера',
        'no_file' => 'Файл не дошёл до сервера, попробуйте ещё раз',
        'not_found' => 'Файл не найден: возможно, его уже удалили',
        'folder_not_found' => 'Папка не найдена: возможно, её уже удалили',
        'nothing_chosen' => 'Сначала выберите файлы',
    ],

    'places' => '{1} :count место|[2,4] :count места|[5,*] :count мест',

    'usage_types' => [
        'unknown' => 'Запись',
    ],
];
