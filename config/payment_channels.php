<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Channel -> provider routing (ADR-0038 §2)
    |--------------------------------------------------------------------------
    |
    | A caller picks a `channel` (mtn_momo, orange_money, card...) —
    | never a provider directly. This is where the Core decides which
    | adapter actually handles it. Kept deliberately flat and simple:
    | one provider per channel platform-wide, with optional per-project
    | overrides, until a second real provider and a real need to diverge
    | per Project both exist.
    |
    */

    'default' => [
        'mtn_momo' => 'my-coolpay',
        'orange_money' => 'my-coolpay',
        'card' => 'my-coolpay',
    ],

    // project_id => [channel => provider_code]. Empty until a Project
    // needs to diverge from the platform default for a channel.
    'project_overrides' => [],
];
