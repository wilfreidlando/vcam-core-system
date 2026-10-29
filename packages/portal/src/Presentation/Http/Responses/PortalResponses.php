<?php

declare(strict_types=1);

namespace Core\Portal\Presentation\Http\Responses;

/**
 * The documented shape of the portal API (written once, as BillingResponses).
 */
final class PortalResponses
{
    public const SESSION = 'array{'
        .'url: string, '
        .'expires_at: string, '
        .'customer_created: bool, '
        .'subscription_created: bool'
        .'}';

    public const SETTINGS = 'array{'
        .'display_name: string, '
        .'logo_url: string|null, '
        .'primary_color: string, '
        .'support_url: string|null, '
        .'locale: string, '
        .'allowed_return_urls: list<string>, '
        .'allow_self_cancel: bool, '
        .'allow_plan_change: bool, '
        .'payment_channels: list<string>'
        .'}';
}
