<?php

return [

    'builder' => [

        'actions' => [

            'clone' => [
                'label' => 'Клонировать',
            ],

            'add' => [
                'label' => 'Добавить в :label',
            ],

            'add_between' => [
                'label' => 'Вставить между блоками',
            ],

            'delete' => [
                'label' => 'Удалить',
            ],

            'reorder' => [
                'label' => 'Переместить',
            ],

            'move_down' => [
                'label' => 'Переместить вниз',
            ],

            'move_up' => [
                'label' => 'Переместить вверх',
            ],

            'collapse' => [
                'label' => 'Свернуть',
            ],

            'expand' => [
                'label' => 'Развернуть',
            ],

            'collapse_all' => [
                'label' => 'Свернуть все',
            ],

            'expand_all' => [
                'label' => 'Развернуть все',
            ],

        ],

    ],

    'checkbox_list' => [

        'actions' => [

            'deselect_all' => [
                'label' => 'Отменить выбор всех',
            ],

            'select_all' => [
                'label' => 'Выбрать все',
            ],

        ],

    ],

    'file_upload' => [

        'editor' => [

            'actions' => [

                'cancel' => [
                    'label' => 'Отмена',
                ],

                'drag_crop' => [
                    'label' => 'Режим перетаскивания "обрезка"',
                ],

                'drag_move' => [
                    'label' => 'Режим перетаскивания "перемещение"',
                ],

                'flip_horizontal' => [
                    'label' => 'Отразить изображение по горизонтали',
                ],

                'flip_vertical' => [
                    'label' => 'Отразить изображение по вертикали',
                ],

                'move_down' => [
                    'label' => 'Переместить изображение вниз',
                ],

                'move_left' => [
                    'label' => 'Переместить изображение влево',
                ],

                'move_right' => [
                    'label' => 'Переместить изображение вправо',
                ],

                'move_up' => [
                    'label' => 'Переместить изображение вверх',
                ],

                'reset' => [
                    'label' => 'Сбросить',
                ],

                'rotate_left' => [
                    'label' => 'Повернуть изображение влево',
                ],

                'rotate_right' => [
                    'label' => 'Повернуть изображение вправо',
                ],

                'save' => [
                    'label' => 'Сохранить',
                ],

                'zoom_100' => [
                    'label' => 'Масштабировать изображение до 100%',
                ],

                'zoom_in' => [
                    'label' => 'Увеличить',
                ],

                'zoom_out' => [
                    'label' => 'Уменьшить',
                ],

            ],

            'fields' => [

                'height' => [
                    'label' => 'Высота',
                    'unit' => 'пикс',
                ],

                'rotation' => [
                    'label' => 'Поворот',
                    'unit' => 'град',
                ],

                'width' => [
                    'label' => 'Ширина',
                    'unit' => 'пикс',
                ],

                'x_position' => [
                    'label' => 'X',
                    'unit' => 'пикс',
                ],

                'y_position' => [
                    'label' => 'Y',
                    'unit' => 'пикс',
                ],

            ],

            'aspect_ratios' => [

                'label' => 'Соотношения сторон',

                'no_fixed' => [
                    'label' => 'Свободное',
                ],

            ],

        ],

        'loading_indicator' => 'Загрузка...',

        'placeholder' => 'Нажмите для выбора файла или перетащите его сюда',

        'preview' => 'Предпросмотр',

        'remove' => 'Удалить',

        'remove_all' => 'Удалить все',

        'upload_new' => 'Загрузить новый',

        'drag_instruction' => 'Перетащите файлы для изменения порядка',

        'upload_tab' => 'Загрузить',

        'url_tab' => 'URL',

        'url_placeholder' => 'Введите URL файла',

        'url_instructions' => 'Введите прямую ссылку на файл.',

    ],

    'rich_editor' => [

        'dialogs' => [

            'link' => [

                'actions' => [
                    'link' => 'Ссылка',
                    'unlink' => 'Убрать ссылку',
                ],

                'label' => 'URL',

                'placeholder' => 'Введите URL',

            ],

        ],

        'toolbar_buttons' => [
            'attach_files' => 'Прикрепить файлы',
            'blockquote' => 'Цитата',
            'bold' => 'Жирный',
            'bullet_list' => 'Маркированный список',
            'code_block' => 'Блок кода',
            'h1' => 'Заголовок',
            'h2' => 'Заголовок',
            'h3' => 'Заголовок',
            'italic' => 'Курсив',
            'link' => 'Ссылка',
            'ordered_list' => 'Нумерованный список',
            'redo' => 'Повторить',
            'strike' => 'Зачёркнутый',
            'underline' => 'Подчёркнутый',
            'undo' => 'Отменить',
        ],

    ],

    'select' => [

        'actions' => [

            'create_option' => [

                'modal' => [

                    'heading' => 'Создать',

                    'actions' => [

                        'create' => [
                            'label' => 'Создать',
                        ],

                        'create_another' => [
                            'label' => 'Создать и создать ещё',
                        ],

                    ],

                ],

            ],

            'edit_option' => [

                'modal' => [

                    'heading' => 'Редактировать',

                    'actions' => [

                        'save' => [
                            'label' => 'Сохранить',
                        ],

                    ],

                ],

            ],

        ],

        'boolean' => [
            'true' => 'Да',
            'false' => 'Нет',
        ],

        'loading_message' => 'Загрузка...',

        'max_items_message' => 'Можно выбрать только :count.',

        'no_search_results_message' => 'Опции, соответствующие вашему поиску, не найдены.',

        'placeholder' => 'Выберите опцию',

        'searching_message' => 'Поиск...',

        'search_prompt' => 'Начните вводить для поиска...',

    ],

    'tags_input' => [

        'placeholder' => 'Новый тег',

    ],

    'textarea' => [

        'placeholder' => 'Введите сообщение...',

    ],

    'toggle_buttons' => [

        'boolean' => [
            'true' => 'Да',
            'false' => 'Нет',
        ],

    ],

    'wizard' => [

        'actions' => [

            'previous_step' => [
                'label' => 'Назад',
            ],

            'next_step' => [
                'label' => 'Далее',
            ],

        ],

    ],

];
