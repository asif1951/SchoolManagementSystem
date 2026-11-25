<?php

return [

    /*
    | How alike two names must be (0–100) for a new student to count as a "likely duplicate" of an
    | existing one, when no Birth Registration Number settles it. The date of birth or the guardian
    | mobile must match as well. 85 catches typos and word-order changes without flagging strangers.
    */
    // most optional (4th) subjects one student may take
    'max_optional_subjects' => 1,

    'duplicate_threshold' => 85,

    /* Photos are stored square, this many pixels wide, as WebP. */
    'photo_size' => 400,

    /*
    | Fee structures (Fees → Create Fee) whose cycle is listed here count toward a student's dues in the transfer check.
    | Monthly structures are left out: the fee module keeps no total "owed so far" for them.
    */
    'fee_cycles_counted_as_dues' => ['yearly', 'annual', 'one-time', 'one_time', 'once', 'admission'],

    /* Largest accepted photo upload, in kilobytes. */
    'photo_max_kb' => 2048,
];
