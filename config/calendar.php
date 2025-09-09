<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Calendar Configuration
    |--------------------------------------------------------------------------
    |
    | Настройки для календаря заявок медицинского центра
    |
    */

    'default_view' => 'timeGridWeek',
    
    'working_hours' => [
        'start' => '08:00:00',
        'end' => '20:00:00',
    ],
    
    'slot_duration' => '00:15:00', // 15 минут
    'snap_duration' => '00:05:00', // 5 минут
    
    'cache' => [
        'enabled' => true,
        'ttl' => 300, // 5 минут
        'prefix' => 'calendar_',
    ],
    
    'filters' => [
        'default_date_range' => 7, // дней
        'max_date_range' => 30, // дней
        'enable_realtime_updates' => true,
    ],
    
    'performance' => [
        'chunk_size' => 1000,
        'enable_query_logging' => env('CALENDAR_QUERY_LOGGING', false),
        'enable_performance_monitoring' => env('CALENDAR_PERFORMANCE_MONITORING', false),
    ],
    
    'colors' => [
        'free_slot' => '#31c090', // зеленый
        'occupied_slot' => '#dc2626', // красный
        'doctor_shift' => '#3b82f6', // синий
        'holiday' => '#f59e0b', // оранжевый
    ],
    
    'roles' => [
        'super_admin' => [
            'can_create' => true,
            'can_edit' => true,
            'can_delete' => true,
            'can_view_all' => true,
        ],
        'partner' => [
            'can_create' => true,
            'can_edit' => true,
            'can_delete' => true,
            'can_view_all' => false, // только свою клинику
        ],
        'doctor' => [
            'can_create' => false,
            'can_edit' => false,
            'can_delete' => false,
            'can_view_all' => false, // только свои заявки
        ],
    ],
    
    'notifications' => [
        'enable_email_notifications' => env('CALENDAR_EMAIL_NOTIFICATIONS', false),
        'enable_sms_notifications' => env('CALENDAR_SMS_NOTIFICATIONS', false),
        'reminder_before_hours' => 24,
    ],
    
    'export' => [
        'enable_pdf_export' => true,
        'enable_excel_export' => true,
        'max_records_per_export' => 10000,
    ],
];
