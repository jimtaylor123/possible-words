<?php

return [
    'size' => (int) env('WORD_BATCH_SIZE', 5),
    'schedule' => env('WORD_BATCH_SCHEDULE', 'cron(0 */6 * * ? *)'),
    'coordinator_items' => (int) env('WORD_BATCH_COORDINATOR_ITEMS', 10),
    'coordinator_seconds' => (int) env('WORD_BATCH_COORDINATOR_SECONDS', 600),
    'lease_ttl_seconds' => (int) env('WORD_BATCH_LEASE_TTL_SECONDS', 720),
    'retry_base_seconds' => (int) env('WORD_BATCH_RETRY_BASE_SECONDS', 60),
    'retry_cap_seconds' => (int) env('WORD_BATCH_RETRY_CAP_SECONDS', 3600),
    'stale_seconds' => (int) env('WORD_BATCH_STALE_SECONDS', 21600),
];
