<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

/**
 * The full set of scope strings any `hasScope()` check across the Core
 * currently guards (payment/webhook/notification/wallet controllers each
 * hardcode their own literal — this is the one place that lists them all
 * together). Review finding, user-directed: an operator issuing a Project's
 * API key should never have to type scopes by hand — a Project's own
 * integration key is meant to use the whole Core, not a hand-picked subset,
 * so every key this Core issues (auto-issued at project creation, or
 * manually via self-service/CLI) is granted `ALL`. If a narrower key is
 * ever needed, that is a new requirement to design for explicitly, not a
 * default to build speculatively today (YAGNI).
 */
final class ApiKeyScopes
{
    /** @var list<string> */
    public const ALL = [
        'payments:read',
        'payments:write',
        'webhooks:read',
        'webhooks:write',
        'notifications:read',
        'notifications:write',
        'notification_channel_sessions:read',
        'notification_channel_sessions:write',
        'wallets:read',
        // ADR-0065 — transfers and recorded withdrawals.
        'wallets:write',
    ];

    /**
     * The wildcard scope — covers every concrete scope. A key issued
     * with `--scope='*'` is the ergonomic "everything for this tenant"
     * shape (ADR-0044) and `ApiKey::hasScope()` recognises it as such.
     * Same rule as `OperatorRole::hasPermission()`.
     */
    public const WILDCARD = '*';
}
