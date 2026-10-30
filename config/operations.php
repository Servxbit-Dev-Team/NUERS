<?php

return [
    // Merchant records are accepted into NUERS. No BIR transport is enabled here.
    'mode' => 'nuers_storage',
    'stale_seconds' => 180,
    'worker_stale_seconds' => 300,
    'queue_warning_seconds' => 600,
    'minimum_free_bytes' => 1024 * 1024 * 1024,
    'integrity_stale_seconds' => 7200,
    'backup' => [
        'enabled' => (bool) env('NUERS_LOCAL_BACKUP_ENABLED', false),
        // Disable only after installing the separate operator backup service.
        'application_scheduler_enabled' => (bool) env('NUERS_BACKUP_APP_SCHEDULER_ENABLED', true),
        'directory' => env('NUERS_BACKUP_DIRECTORY'),
        'key_file' => env('NUERS_BACKUP_KEY_FILE'),
        'mysqldump' => env('NUERS_MYSQLDUMP_PATH', '/usr/bin/mysqldump'),
        'max_age_hours' => 26,
        'max_bytes' => 5 * 1024 * 1024 * 1024,
    ],
];
