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

    'scheduler' => [

        /*
        |----------------------------------------------------------------------
        | Learning steps, in minutes
        |----------------------------------------------------------------------
        |
        | The gap plain SM-2 leaves: a brand new card jumps straight to a
        | one-day interval, so you see a sentence once, get it right by luck,
        | and it is gone by tomorrow. These steps drill it minutes apart inside
        | the same session before it graduates onto the day-scale ladder.
        |
        */

        'learning_steps' => [1, 10],

        /*
        | The same idea for a graduated card that was forgotten. Shorter,
        | because the card is being re-learned rather than learned.
        */

        'relearning_steps' => [10],

        /*
        |----------------------------------------------------------------------
        | Graduation
        |----------------------------------------------------------------------
        |
        | Where a card lands when it leaves the learning steps: one day for a
        | normal pass, four for one answered Easy — which skips the steps
        | entirely, since there is nothing to drill.
        |
        */

        'graduating_interval' => 1,
        'easy_interval' => 4,

        /*
        |----------------------------------------------------------------------
        | Interval multipliers
        |----------------------------------------------------------------------
        |
        | Good multiplies by the card's own easiness. Hard uses a fixed, smaller
        | multiplier so a card that cost real effort grows slowly instead of at
        | the same rate as an instant recall. Easy adds a bonus on top.
        |
        */

        'hard_multiplier' => 1.2,
        'easy_bonus' => 1.3,

        /*
        |----------------------------------------------------------------------
        | Easiness bounds
        |----------------------------------------------------------------------
        |
        | The floor stops a run of failures collapsing the interval towards
        | zero, which would leave the card reappearing forever. The ceiling
        | stops a long run of Easy pushing a card years out on a multiplier
        | that no longer reflects anything real.
        |
        */

        'starting_easiness' => 2.5,
        'minimum_easiness' => 1.3,
        'maximum_easiness' => 3.5,

        /*
        |----------------------------------------------------------------------
        | Lapses
        |----------------------------------------------------------------------
        |
        | What is left of the interval after forgetting a graduated card. Zero
        | restarts it, which is the honest answer: you did not remember it.
        |
        */

        'lapse_multiplier' => 0.0,
        'minimum_lapse_interval' => 1,

        /*
        |----------------------------------------------------------------------
        | Maximum interval, in days
        |----------------------------------------------------------------------
        |
        | Past a year the scheduling is guesswork and you would rather be shown
        | the card than trust the estimate.
        |
        */

        'maximum_interval' => 365,

        /*
        |----------------------------------------------------------------------
        | Interval fuzz, as a percentage
        |----------------------------------------------------------------------
        |
        | Every interval is jittered slightly. Without it, everything added on
        | the same day comes due on the same day forever and the daily load
        | arrives in spikes with empty days between them.
        |
        | Set to 0 to make scheduling deterministic.
        |
        */

        'fuzz_percent' => 5,

        /*
        |----------------------------------------------------------------------
        | Leech threshold
        |----------------------------------------------------------------------
        |
        | A card forgotten this many times is not being learned — the sentence
        | is bad, or it has more than one unknown in it. Suspend it and say so,
        | rather than grinding on it every day.
        |
        */

        'leech_threshold' => 8,

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
