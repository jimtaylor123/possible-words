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
    | RDAP is third-party infrastructure. Failed requests are retried by a
    | later command invocation using durable capped exponential backoff.
    |
    */
    'timeout' => (int) env('DOMAIN_TIMEOUT', 10),

    'retry_base_seconds' => (int) env('DOMAIN_RETRY_BASE_SECONDS', 60),

    'retry_cap_seconds' => (int) env('DOMAIN_RETRY_CAP_SECONDS', 3600),

    'retry_jitter_seconds' => (int) env('DOMAIN_RETRY_JITTER_SECONDS', 15),

    'max_retry_after_seconds' => (int) env('DOMAIN_MAX_RETRY_AFTER_SECONDS', 600),

    // Stop before this invocation can reach its platform timeout. The reserve
    // covers one final HTTP attempt and its persistence write after pacing.
    'run_max_seconds' => (int) env('DOMAIN_RUN_MAX_SECONDS', 600),

    'run_deadline_reserve_seconds' => (int) env('DOMAIN_RUN_DEADLINE_RESERVE_SECONDS', 30),

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Conservative spacing before each request. Jitter keeps concurrent cold
    | starts from synchronizing. --throttle remains a requests/second override.
    |
    */
    'request_interval_ms' => (int) env('DOMAIN_REQUEST_INTERVAL_MS', 1000),

    'request_jitter_ms' => (int) env('DOMAIN_REQUEST_JITTER_MS', 100),

    // Must cover the Lambda's complete 720-second maximum lifetime. A lease
    // that survives a timed-out invocation prevents a retry from overlapping
    // its RDAP traffic; it then expires so a later event can resume the work.
    'run_lock_ttl_seconds' => (int) env('DOMAIN_RUN_LOCK_TTL_SECONDS', 720),
];
