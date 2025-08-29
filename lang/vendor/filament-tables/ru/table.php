<?php

return [

    'column_toggle' => [

        'heading' => 'Столбцы',

    ],

    'columns' => [

        'text' => [

            'actions' => [
                'collapse_list' => 'Показать на :count меньше',
                'expand_list' => 'Показать ещё :count',
            ],

            'more_list_items' => 'и ещё :count',

        ],

    ],

    'fields' => [

        'bulk_select_page' => [
            'label' => 'Выбрать/отменить все элементы для массовых действий.',
        ],

        'bulk_select_record' => [
            'label' => 'Выбрать/отменить элемент :key для массовых действий.',
        ],

        'bulk_select_group' => [
            'label' => 'Выбрать/отменить группу :title для массовых действий.',
        ],

        'search' => [
            'label' => 'Поиск',
            'placeholder' => 'Поиск',
            'indicator' => 'Поиск',
        ],

    ],

    'summary' => [

        'heading' => 'Итого',

        'subheadings' => [
            'all' => 'Все :label',
            'group' => 'Итого :group',
            'page' => 'Эта страница',
        ],

        'summarizers' => [

            'average' => [
                'label' => 'Среднее',
            ],

            'count' => [
                'label' => 'Количество',
            ],

            'sum' => [
                'label' => 'Сумма',
            ],

        ],

    ],

    'actions' => [

        'disable_reordering' => [
            'label' => 'Завершить изменение порядка записей',
        ],

        'enable_reordering' => [
            'label' => 'Изменить порядок записей',
        ],

        'filter' => [
            'label' => 'Фильтр',
        ],

        'group' => [
            'label' => 'Группировка',
        ],

        'open_bulk_actions' => [
            'label' => 'Массовые действия',
        ],

        'toggle_columns' => [
            'label' => 'Переключить столбцы',
        ],

    ],

    'empty' => [

        'heading' => 'Записи не найдены',

        'description' => 'Создайте :model для начала работы.',

    ],

    'filters' => [

        'actions' => [

            'remove' => [
                'label' => 'Удалить фильтр',
            ],

            'remove_all' => [
                'label' => 'Удалить все фильтры',
                'tooltip' => 'Удалить все фильтры',
            ],

            'reset' => [
                'label' => 'Сбросить',
            ],

        ],

        'heading' => 'Фильтры',

        'indicator' => 'Активные фильтры',

        'multi_select' => [
            'placeholder' => 'Все',
        ],

        'select' => [
            'placeholder' => 'Все',
        ],

        'trashed' => [

            'label' => 'Удалённые записи',

            'only_trashed' => 'Только удалённые записи',

            'with_trashed' => 'С удалёнными записями',

            'without_trashed' => 'Без удалённых записей',

        ],

    ],

    'grouping' => [

        'fields' => [

            'group' => [
                'label' => 'Группировать по',
                'placeholder' => 'Группировать по',
            ],

            'direction' => [

                'label' => 'Направление группировки',

                'options' => [
                    'asc' => 'По возрастанию',
                    'desc' => 'По убыванию',
                ],

            ],

        ],

    ],

    'reorder_indicator' => 'Перетащите записи в нужном порядке.',

    'selection_indicator' => [

        'selected_count' => 'Выбрана 1 запись|Выбрано :count записей',

        'actions' => [

            'select_all' => [
                'label' => 'Выбрать все :count',
            ],

            'deselect_all' => [
                'label' => 'Отменить выбор',
            ],

        ],

    ],

    'sorting' => [

        'fields' => [

            'column' => [
                'label' => 'Сортировать по',
            ],

            'direction' => [

                'label' => 'Направление сортировки',

                'options' => [
                    'asc' => 'По возрастанию',
                    'desc' => 'По убыванию',
                ],

            ],

        ],

    ],

];
