<?php

declare(strict_types=1);

namespace Core\Notification;

/**
 * Canonical notification lifecycle states (spec technique §9.4).
 *
 * Lives directly under `src/`, outside every layer directory — like
 * `Core\Payment\PaymentStatus` — so it is shared identically by
 * `Contracts`, `Domain` and `Application` without either ever importing
 * the other (ADR-0041 §4).
 *
 *   queued    — accepted by the Core, not yet handed to a provider (or a
 *               provider attempt is in flight).
 *   sent      — a provider accepted the message for delivery.
 *   delivered — the provider confirmed delivery to the recipient's device.
 *   read      — the recipient opened the message (not every channel
 *               reports this; channels that don't just stop at `sent` or
 *               `delivered`).
 *   failed    — every configured provider (primary + fallbacks) failed,
 *               or the provider reported a definitive delivery failure.
 */
enum NotificationStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Read = 'read';
    case Failed = 'failed';

    /** Whether this status is terminal (no further transition expected). */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Read, self::Failed => true,
            self::Queued, self::Sent, self::Delivered => false,
        };
    }

    /**
     * The forward lifecycle graph: queued -> {sent, failed}, sent ->
     * {delivered, failed}, delivered -> {read}. `read` and `failed` have
     * no valid outgoing transition.
     */
    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Queued => in_array($next, [self::Sent, self::Failed], true),
            self::Sent => in_array($next, [self::Delivered, self::Failed], true),
            self::Delivered => $next === self::Read,
            self::Read, self::Failed => false,
        };
    }
}
