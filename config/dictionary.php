<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Merriam-Webster API
    |--------------------------------------------------------------------------
    |
    | Sign up for a free API key at https://dictionaryapi.com/
    |
    */
    'merriam_webster_key' => env('MERRIAM_WEBSTER_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Banned Words File
    |--------------------------------------------------------------------------
    |
    | Path to a JSON file containing words that should always be rejected
    | during generation. This is a fast sync check before API lookups.
    |
    */
    'banned_words_path' => storage_path('app/dictionary/banned-words.json'),

    /*
    |--------------------------------------------------------------------------
    | Lookup Resilience
    |--------------------------------------------------------------------------
    |
    | The free dictionary API is flaky (Cloudflare 522s are common). Retry a
    | failed lookup with a linear backoff before giving up on a word, so an
    | unreachable API is not mistaken for "the word is not in the dictionary".
    |
    */
    'timeout' => (int) env('DICTIONARY_TIMEOUT', 10),

    'attempts' => (int) env('DICTIONARY_ATTEMPTS', 3),

    'retry_sleep_ms' => (int) env('DICTIONARY_RETRY_SLEEP_MS', 400),

    /*
    |--------------------------------------------------------------------------
    | Banned Words Enforcement
    |--------------------------------------------------------------------------
    |
    | When true, a missing/unreadable banned-words file is a hard error rather
    | than an empty list. Production sets this so a packaging mistake cannot
    | silently disable the generation gate.
    |
    */
    'banned_words_required' => (bool) env('BANNED_WORDS_REQUIRED', false),

    /*
    |--------------------------------------------------------------------------
    | API Toggles
    |--------------------------------------------------------------------------
    |
    */
    'free_dictionary_enabled' => env('FREE_DICTIONARY_ENABLED', true),

    'merriam_webster_enabled' => env('MERRIAM_WEBSTER_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Max API calls per second when backfilling via the artisan command.
    |
    */
    'throttle_per_second' => env('DICTIONARY_THROTTLE', 2),
];
