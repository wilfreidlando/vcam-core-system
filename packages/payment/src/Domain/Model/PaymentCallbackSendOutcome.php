<?php

declare(strict_types=1);

namespace Core\Payment\Domain\Model;

use Core\Support\Http\HttpStatusClassifier;

/**
 * Result of one HTTP attempt to a Payment's `callbackUrl` — mirrors
 * `Core\Webhook\Domain\Model\WebhookSendOutcome`'s shape, including
 * the `isPermanentFailure` flag added by ADR-0055.
 *
 * On a permanent failure, `DeliverPaymentCallbackJob` calls
 * `$this->fail($outcome->error)` directly (no retry budget). There is
 * no DLQ on this plane (ADR-0038 §4 trade-off), so the only feedback
 * path is the `failed()` hook's structured log + the Laravel
 * `failed_jobs` row.
 */
final readonly class PaymentCallbackSendOutcome
{
    private function __construct(
        public bool $success,
        public ?int $httpStatus,
        public ?string $error,
        public bool $isPermanentFailure = false,
    ) {}

    public static function success(int $httpStatus): self
    {
        return new self(true, $httpStatus, null, false);
    }

    public static function failure(?int $httpStatus, string $error): self
    {
        return new self(
            false,
            $httpStatus,
            $error,
            HttpStatusClassifier::isPermanentFailure($httpStatus),
        );
    }

    public static function noCallbackConfigured(): self
    {
        return new self(true, null, null, false);
    }
}
