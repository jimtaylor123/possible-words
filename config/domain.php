<?php

return [
    /*
    |--------------------------------------------------------------------------
    | .com RDAP Check
    |--------------------------------------------------------------------------
    |
    | Verdicts come from Verisign's free public RDAP endpoint. There is no API
    | key anywhere in this feature: the endpoint is open, so the only thing to
    | manage is politeness (throttle) and resilience (retries).
    |
    */
    'enabled' => (bool) env('DOMAIN_CHECK_ENABLED', true),

    'rdap_base_url' => env('DOMAIN_RDAP_URL', 'https://rdap.verisign.com/com/v1/domain'),

    /*
    |--------------------------------------------------------------------------
    | Lookup Resilience
    |--------------------------------------------------------------------------
    |
    | RDAP is third-party infrastructure. Retry a failed lookup with a linear
    | backoff before giving up, so a transient error is recorded as
    | check_failed (unknown) rather than guessed through.
    |
    */
    'timeout' => (int) env('DOMAIN_TIMEOUT', 10),

    'attempts' => (int) env('DOMAIN_ATTEMPTS', 3),

    'retry_sleep_ms' => (int) env('DOMAIN_RETRY_SLEEP_MS', 400),

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Max requests per second when backfilling via the artisan command.
    |
    */
    'throttle_per_second' => (int) env('DOMAIN_THROTTLE', 4),

    // Must cover the Lambda's complete 720-second maximum lifetime. A lease
    // that survives a timed-out invocation prevents a retry from overlapping
    // its RDAP traffic; it then expires so a later event can resume the work.
    'run_lock_ttl_seconds' => (int) env('DOMAIN_RUN_LOCK_TTL_SECONDS', 720),
];
