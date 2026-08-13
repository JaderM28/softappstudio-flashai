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

    ],

    'session' => [

        /*
        |----------------------------------------------------------------------
        | New sentences per day
        |----------------------------------------------------------------------
        |
        | Capped per sentence rather than per card, because sentences are the
        | unit the user thinks in — one sentence becomes two or three cards.
        |
        | Keep this low. A new card generates roughly ten reviews over its first
        | months, so at two cards per sentence, five sentences a day settles at
        | around a hundred reviews a day once the schedule fills in. Decks can
        | override it; this is the starting point.
        |
        */

        'new_per_day' => (int) env('FLASHAI_NEW_PER_DAY', 5),

        /*
        |----------------------------------------------------------------------
        | Reviews per day
        |----------------------------------------------------------------------
        |
        | A ceiling so that coming back from a holiday presents a session that
        | can actually be finished rather than a backlog of six hundred cards.
        |
        */

        'reviews_per_day' => (int) env('FLASHAI_REVIEWS_PER_DAY', 120),

        /*
        |----------------------------------------------------------------------
        | Bury siblings
        |----------------------------------------------------------------------
        |
        | After grading one card of a sentence, hold its other cards back until
        | the next review day. Otherwise the second question follows the first
        | with the answer still on screen, and grading it means nothing.
        |
        */

        'bury_siblings' => (bool) env('FLASHAI_BURY_SIBLINGS', true),

    ],

];
