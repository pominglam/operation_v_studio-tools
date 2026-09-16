<?php

declare(strict_types=1);

return [

    'retention' => [
        'recent_days' => (int) env('DB_BACKUP_RETENTION_RECENT_DAYS', 14),
        'weekly_days' => (int) env('DB_BACKUP_RETENTION_WEEKLY_DAYS', 180),
        'minimum_count' => (int) env('DB_BACKUP_RETENTION_MINIMUM', 5),
    ],

    'schedule' => [
        'enabled' => filter_var(env('DB_BACKUP_SCHEDULE_ENABLED', true), FILTER_VALIDATE_BOOL),
        'host_orchestrator' => filter_var(env('DB_BACKUP_HOST_ORCHESTRATOR', false), FILTER_VALIDATE_BOOL),
        'backup_time' => env('DB_BACKUP_SCHEDULE_TIME', '02:30'),
        'purge_time' => env('DB_BACKUP_PURGE_TIME', '03:30'),
    ],

];
