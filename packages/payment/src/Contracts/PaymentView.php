<?php

declare(strict_types=1);

namespace Core\Payment\Contracts;

use Core\Payment\PaymentStatus;

/**
 * The read model returned to a caller — never `Core\Payment\Domain\Model\Payment`,
 * which stays internal (G3c). Carries only canonical vocabulary: no
 * `provider_code`, no raw provider fields.
 */
final readonly class PaymentView
{
    public function __construct(
        public string $id,
        public string $channel,
        public PaymentStatus $status,
        /** Minor units (e.g. cents) — never a float, per G5. */
        public int $amount,
        public string $currency,
        public bool $requiresAction,
        public ?string $actionHint,
        public ?string $paymentUrl,
        public ?string $errorMessage,
    ) {}
}
