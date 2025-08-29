<?php

return [

    'title' => 'Вход',

    'heading' => 'Войти в аккаунт',

    'actions' => [

        'register' => [
            'before' => 'или',
            'label' => 'создайте аккаунт',
        ],

        'request_password_reset' => [
            'label' => 'Забыли пароль?',
        ],

    ],

    'form' => [

        'email' => [
            'label' => 'Email адрес',
        ],

        'password' => [
            'label' => 'Пароль',
        ],

        'remember' => [
            'label' => 'Запомнить меня',
        ],

        'actions' => [

            'authenticate' => [
                'label' => 'Войти',
            ],

        ],

    ],

    'messages' => [

        'failed' => 'Неверные учётные данные.',

    ],

    'notifications' => [

        'throttled' => [
            'title' => 'Слишком много попыток входа',
            'body' => 'Попробуйте снова через :seconds секунд.',
        ],

    ],

];
