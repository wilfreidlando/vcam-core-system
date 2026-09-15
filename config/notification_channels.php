<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Channel -> ordered provider fallback chain (ADR-0041 §3)
    |--------------------------------------------------------------------------
    |
    | A caller picks a `channel` (whatsapp, sms, email...) — never a
    | provider directly. Each channel maps to an ORDERED list of provider
    | codes: SendNotificationUseCase tries them in order, falling back to
    | the next on failure. Empty until a first concrete adapter exists
    | (ADR-0041) — no channel is routable yet.
    |
    */

    'default' => [
        // 'whatsapp' => ['whatsapp-selfhosted', 'whatsapp-cloud'],
    ],

    // project_id => [channel => ordered list of provider_code].
    'project_overrides' => [],
];
