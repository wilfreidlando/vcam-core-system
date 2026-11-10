<?php

declare(strict_types=1);

namespace Core\Notification\Contracts;

/**
 * What a system notification did (ADR-0118 §3).
 *
 * A notification that was never written — because the Core's own session
 * does not exist — is not a failure of the send: it is a send that did not
 * happen, and it says why (`reason`). The business event that triggered it
 * is already in the outbox; nothing is broken (ADR-0118 §7).
 */
final readonly class SystemNotificationResult
{
    private function __construct(
        public ?string $notificationId,
        public ?string $status,
        public ?string $reason,
    ) {}

    /**
     * Nothing was written, and here is why.
     */
    public static function notSent(string $reason): self
    {
        return new self(null, null, $reason);
    }

    /**
     * A notification exists — sent, or refused by the provider (then
     * `$status` is 'failed' and `$reason` carries the provider's complaint).
     */
    public static function of(NotificationView $view): self
    {
        return new self($view->id, $view->status, $view->errorMessage);
    }

    /**
     * Whether a notification row was written at all.
     */
    public function wasWritten(): bool
    {
        return $this->notificationId !== null;
    }
}
