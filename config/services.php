<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
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

    'gemini' => [
        'key' => env('GEMINI_API_KEY'),
        'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),

        // gemini-2.0-flash, which the original plan named, was shut down.
        // flash-lite is the cheapest and fastest of the current line, and
        // filling in six fields about one sentence asks nothing more of it.
        'model' => env('GEMINI_MODEL', 'gemini-3.5-flash-lite'),

        // The user is waiting on this call, so it fails fast rather than
        // leaving them looking at a spinner.
        'timeout' => (int) env('GEMINI_TIMEOUT', 20),

        // Looking a word up twice is common, and the free tier is 1,500 calls
        // a day. Set to 0 to disable caching.
        'cache_ttl' => (int) env('GEMINI_CACHE_TTL', 60 * 60 * 24 * 30),
    ],

    'unsplash' => [
        'key' => env('UNSPLASH_ACCESS_KEY'),
        'base_url' => env('UNSPLASH_BASE_URL', 'https://api.unsplash.com'),
        'timeout' => (int) env('UNSPLASH_TIMEOUT', 15),

        // The free tier allows 50 requests an hour, and every picture costs two
        // — the search and the download report. Held below that so a burst of
        // sentences paces itself instead of failing halfway through.
        'per_hour' => (int) env('UNSPLASH_PER_HOUR', 20),
    ],

    'google_tts' => [
        'key' => env('GOOGLE_TTS_API_KEY'),
        'base_url' => env('GOOGLE_TTS_BASE_URL', 'https://texttospeech.googleapis.com/v1'),
        'timeout' => (int) env('GOOGLE_TTS_TIMEOUT', 20),

        // A little under natural pace: these are sentences being learned, not
        // listened to for pleasure.
        'speaking_rate' => (float) env('GOOGLE_TTS_SPEAKING_RATE', 0.9),
    ],

];
