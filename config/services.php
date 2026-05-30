<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', rtrim((string) env('APP_URL', 'http://localhost'), '/').'/auth/google/callback'),
    ],

    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        'voice' => env('TTS_VOICE', 'alloy'),
        'model' => env('TTS_MODEL', 'tts-1'),
    ],

    'tts' => [
        'default' => env('TTS_PROVIDER', 'edge-tts'),

        'providers' => [
            'edge-tts' => [
                'voice' => env('EDGE_TTS_VOICE', 'en-GB-SoniaNeural'),
            ],
            'openai' => [
                'voice' => env('TTS_VOICE', 'alloy'),
                'model' => env('TTS_MODEL', 'tts-1'),
            ],
        ],
    ],

];
