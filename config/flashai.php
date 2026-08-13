<?php

return [

    'review' => [

        /*
        |----------------------------------------------------------------------
        | Hour the review "day" rolls over
        |----------------------------------------------------------------------
        |
        | SM-2 intervals are measured in days, so a card falls due at the start
        | of a day rather than exactly N*24h after the last review. Without
        | this, reviewing at 21:00 pushes the card past the next morning's
        | session and the due time drifts later and later on every review.
        |
        | The cutoff is 4am rather than midnight so that someone reviewing late
        | at night does not see the same card again an hour later.
        |
        */

        'day_starts_at_hour' => (int) env('FLASHAI_DAY_STARTS_AT_HOUR', 4),

        /*
        |----------------------------------------------------------------------
        | Cards per session
        |----------------------------------------------------------------------
        |
        | Keeps a backlog of several weeks from turning the daily session into
        | something nobody will start. Set to null for no limit.
        |
        */

        'daily_limit' => env('FLASHAI_DAILY_LIMIT', 50),

    ],

];
