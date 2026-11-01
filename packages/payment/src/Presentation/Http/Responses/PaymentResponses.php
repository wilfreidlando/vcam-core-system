<?php

declare(strict_types=1);

namespace Core\Payment\Presentation\Http\Responses;

/**
 * The documented shape of the payment listing (ADR-0088 §2) — it follows
 * `PaymentJson::status()` key for key. The `payment_url`, `action_hint`
 * and `failure_reason` keys appear only when they apply.
 */
final class PaymentResponses
{
    public const PAYMENT = 'array{'
        .'id: string, '
        .'status: string, '
        .'is_final: bool, '
        .'amount: int, '
        .'currency: string, '
        .'channel: string, '
        .'created_at: string|null, '
        .'updated_at: string|null, '
        .'payment_url?: string, '
        .'action_hint?: string, '
        .'failure_reason?: string'
        .'}';

    public const PAYMENT_PAGE = 'array{'
        .'data: list<'.self::PAYMENT.'>, '
        .'next_cursor: string|null'
        .'}';
}
