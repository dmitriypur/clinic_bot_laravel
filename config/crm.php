<?php

return [
    'providers' => [
        'none' => [
            'label' => 'Не использовать',
            'fields' => [],
        ],
        'bitrix24' => [
            'label' => 'Bitrix24',
            'fields' => [
                'webhook_url' => ['label' => 'Webhook URL', 'required' => true],
                'title_prefix' => ['label' => 'Префикс названия лида', 'default' => 'Заявка'],
                'category_id' => ['label' => 'ID воронки (CATEGORY_ID)'],
                'stage_id' => ['label' => 'ID стадии (STAGE_ID)'],
            ],
        ],
        'onec_crm' => [
            'label' => '1С (уведомления)',
            'fields' => [
                'webhook_url' => ['label' => 'Webhook URL', 'required' => true],
                'token' => ['label' => 'Token', 'required' => true],
            ],
        ],
        'albato' => [
            'label' => 'Albato',
            'fields' => [
                'webhook_url' => ['label' => 'Webhook URL', 'required' => true],
                'secret' => ['label' => 'Secret', 'required' => true],
            ],
        ],
        'amo_crm' => [
            'label' => 'AmoCRM',
            'fields' => [
                'webhook_url' => ['label' => 'Webhook URL', 'required' => true],
                'token' => ['label' => 'Token', 'required' => true],
                'status_id' => ['label' => 'ID статуса'],
                'lead_prefix' => ['label' => 'Префикс сделки', 'default' => 'Заявка'],
            ],
        ],
    ],
];
