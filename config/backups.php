<?php

return [
    'source_disk' => env('BACKUP_SOURCE_DISK', 's3'),
    'destination_disk' => env('BACKUP_DESTINATION_DISK', 'backups'),
    'prefix' => env('BACKUP_PREFIX', 'runs'),
    'production_database' => env('TURSO_PRODUCTION_DATABASE'),
    'temporary_database_prefix' => 'possiblewords-restore-check',
    'turso_cli_path' => env('TURSO_CLI_PATH', base_path('bin/turso')),
    'turso_api_token' => env('TURSO_BACKUP_API_TOKEN'),
    'turso_organization' => env('TURSO_ORGANIZATION'),
    'turso_platform_api_url' => env('TURSO_PLATFORM_API_URL', 'https://api.turso.tech'),
    'aws_region' => env('AWS_DEFAULT_REGION', 'eu-west-1'),
];
