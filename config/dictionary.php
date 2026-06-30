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
