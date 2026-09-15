<?php

declare(strict_types=1);

namespace Core\Payment\Infrastructure\Provider\MyCoolPay;

use Core\Payment\Contracts\PaymentStatusMapper;
use Core\Payment\PaymentStatus;
use Core\Support\Integration\Exception\UnknownProviderStatusException;
use Core\Support\Integration\Provider\ProviderCode;

/**
 * Maps MyCoolPay's `transaction_status` strings to the canonical
 * `PaymentStatus` enum.
 *
 * MyCoolPay exposes three terminal statuses (`SUCCESS`, `CANCELED`,
 * `FAILED`) plus in-flight signals (`PENDING`, `REQUIRE_OTP`,
 * `USSD_OK`). All values that aren't terminal are mapped to `Pending`
 * so the caller can drive a UI without knowing the provider's
 * vocabulary.
 *
 * G6 — these strings MUST NOT appear outside this file or the
 * provider-specific exception classes.
 */
final readonly class MyCoolPayStatusMapper implements PaymentStatusMapper
{
    public function __construct(
        private ProviderCode $providerCode,
    ) {}

    public function map(string $raw): PaymentStatus
    {
        return match (strtoupper($raw)) {
            RawMyCoolPayStatus::SUCCESS => PaymentStatus::Succeeded,
            RawMyCoolPayStatus::CANCELED => PaymentStatus::Canceled,
            RawMyCoolPayStatus::FAILED => PaymentStatus::Failed,
            RawMyCoolPayStatus::PENDING => PaymentStatus::Pending,
            // In-flight signals from Payin: the customer is being asked
            // to do something (enter OTP, dial USSD, etc.). The caller
            // surfaces `requiresAction = true` via the receipt and waits
            // for the next webhook.
            RawMyCoolPayStatus::REQUIRE_OTP => PaymentStatus::Pending,
            RawMyCoolPayStatus::USSD_OK => PaymentStatus::Pending,
            default => throw UnknownProviderStatusException::forValue(
                $this->providerCode,
                $raw,
            ),
        };
    }
}
