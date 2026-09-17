<?php

declare(strict_types=1);

namespace Core\Notification\Contracts;

/**
 * The read model returned to a caller — never
 * `Core\Notification\Domain\Model\Notification`, which stays internal
 * (G3c). Carries only canonical vocabulary: no raw provider status.
 *
 * `$status` is the canonical `NotificationStatus` enum's `->value`, not
 * the enum itself (ADR-0046, follow-up to ADR-0045 §3) — same reasoning
 * as `Core\Payment\Contracts\PaymentView::$status`: the enum lives
 * top-level, outside `Contracts/`, specifically to stay out of reach of
 * every other module (G3c); typing a Contracts DTO's public property
 * with it would let a cross-module consumer depend on it via property
 * access without the guardrail ever seeing the reference.
 */
final readonly class NotificationView
{
    public function __construct(
        public string $id,
        public string $channel,
        public string $status,
        public string $contentType,
        public ?string $errorMessage,
    ) {}
}
