<?php

return [
    'enabled' => env('BOOKING_MONITORING_ENABLED', false),
    'url' => env('BOOKING_MONITORING_URL', 'http://85.93.54.201:5490/unf_telephony'),
    'run_times' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('BOOKING_MONITORING_RUN_TIMES', '07:00,12:00,17:00'))
    ))),
    'connect_timeout' => (int) env('BOOKING_MONITORING_CONNECT_TIMEOUT', 5),
    'timeout' => (int) env('BOOKING_MONITORING_TIMEOUT', 10),
    'lock_seconds' => (int) env('BOOKING_MONITORING_LOCK_SECONDS', 60),

    'bitrix' => [
        'webhook_url' => env('BOOKING_MONITORING_BITRIX_WEBHOOK_URL'),
        'dialog_id' => env('BOOKING_MONITORING_BITRIX_DIALOG_ID'),
    ],
];
