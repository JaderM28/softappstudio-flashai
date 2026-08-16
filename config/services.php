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

        /*
        | Speech runs through the same endpoint and the same key as the
        | sentences above — which is the whole reason it is used. Google Cloud
        | Text-to-Speech has the more generous free tier, but it will not enable
        | the API without a billing account and a card on file.
        */
        'tts' => [
            // Preview, like the rest of the TTS line, so the model name is
            // likelier to move than the flash-lite one above.
            'model' => env('GEMINI_TTS_MODEL', 'gemini-3.1-flash-tts-preview'),

            // One of thirty prebuilt voices. Chosen once; the pace is asked for
            // in the prompt, since this API has no speakingRate.
            'voice' => env('GEMINI_TTS_VOICE', 'Kore'),

            'timeout' => (int) env('GEMINI_TTS_TIMEOUT', 30),
        ],
    ],

    /*
    | Workers AI, over its REST endpoint — there is no Worker to deploy, just a
    | POST like any other. It reads the cards aloud.
    |
    | Measured against the live API rather than assumed: Aura answers in about
    | a second with an MP3 already encoded, and costs about 1.36 neurons per
    | character. At 10,000 free neurons a day and roughly 70 neurons a note,
    | that is about 140 notes a day — against a default allowance of five.
    |
    | Gemini's speech tier, by comparison, was measured at ten requests in a
    | short window and answers in six seconds with raw PCM that has to have a
    | WAV header built for it and then be compressed. This is the primary for
    | all three reasons.
    */
    'cloudflare' => [
        'account_id' => env('CLOUDFLARE_ACCOUNT_ID'),
        'token' => env('CLOUDFLARE_API_TOKEN'),
        'base_url' => env('CLOUDFLARE_BASE_URL', 'https://api.cloudflare.com/client/v4'),

        'tts' => [
            'model' => env('CLOUDFLARE_TTS_MODEL', '@cf/deepgram/aura-1'),

            // One of twelve. Chosen once and then never thought about, which is
            // the point: a learner should hear the same voice every day.
            'voice' => env('CLOUDFLARE_TTS_VOICE', 'angus'),

            'timeout' => (int) env('CLOUDFLARE_TTS_TIMEOUT', 30),
        ],

        /*
        | Whisper, for finding where each word falls inside a clip we made.
        | Measured at 1.83 neurons for a five-second sentence — paid once per
        | note, after which tapping any word in it costs nothing.
        */
        'transcription' => [
            'model' => env('CLOUDFLARE_TRANSCRIBE_MODEL', '@cf/openai/whisper-large-v3-turbo'),
            'timeout' => (int) env('CLOUDFLARE_TRANSCRIBE_TIMEOUT', 30),
        ],
    ],

    /*
    | Tried first for pictures: a hundred times Unsplash's ceiling, no approval
    | process, and it carries illustrations as well as photographs — often the
    | clearer flashcard. It forbids permanent hotlinking, which is why the
    | picture is downloaded and stored rather than linked.
    */
    'pixabay' => [
        'key' => env('PIXABAY_API_KEY'),
        'base_url' => env('PIXABAY_BASE_URL', 'https://pixabay.com/api'),
        'timeout' => (int) env('PIXABAY_TIMEOUT', 15),

        // Their documented limit is 100 per 60 seconds. Held well below it
        // because nothing here is in a hurry.
        'per_hour' => (int) env('PIXABAY_PER_HOUR', 300),
    ],

    /*
    | The fallback, and the reason the chain has three links: 800M+ openly
    | licensed works aggregated from Flickr, Wikimedia and elsewhere. No key at
    | all, which also makes it the one provider that cannot be misconfigured.
    */
    'openverse' => [
        'base_url' => env('OPENVERSE_BASE_URL', 'https://api.openverse.org/v1'),
        'timeout' => (int) env('OPENVERSE_TIMEOUT', 15),
        'per_hour' => (int) env('OPENVERSE_PER_HOUR', 100),
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

];
