<?php

return [
    'disk' => env('DB_BACKUP_DISK', 'local'),
    'directory' => env('DB_BACKUP_DIRECTORY', 'backups/database'),
    'mysqldump_path' => env('MYSQLDUMP_PATH', '/usr/bin/mysqldump'),
    'timeout' => (int) env('DB_BACKUP_TIMEOUT', 300),
];
