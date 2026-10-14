<?php

declare(strict_types=1);

namespace Core\Payment\Contracts;

use Core\Payment\PaymentFlow;

/**
 * The public-facing command — what a Project asks for when it starts a
 * payment, distinct from `InitiatePaymentCommand` (the gateway-transport
 * DTO a `PaymentGateway` adapter actually receives; `StartPaymentUseCase`
 * builds one from this once the provider is resolved).
 *
 * `channel` (never `provider`) is how the caller expresses "pay with
 * MTN" — ADR-0038 §1. `idempotencyKey` is the same value as the HTTP
 * `Idempotency-Key` header the `idempotency` middleware already
 * required (ADR-0025/G7) — reused here as this Core's own protection
 * against a retried request re-charging the customer at the provider,
 * one key for both concerns rather than two.
 *
 * `walletRecipientExternalRef`, when set, is the caller's explicit,
 * per-payment opt-in (ADR-0037) to credit a wallet once this payment
 * settles — never inferred from success alone. `null` means this
 * payment is plain encaissement: no Party, no Wallet, ever touched.
 */
final readonly class StartPaymentCommand
{
    public function __construct(
        public string $projectId,
        public PaymentChannel $channel,
        /** Minor units (e.g. cents) — never a float, per G5. */
        public int $amount,
        public string $currency,
        public string $reason,
        public string $idempotencyKey,
        public ?string $customerPhoneNumber = null,
        public ?string $customerName = null,
        public ?string $customerEmail = null,
        public ?string $customerLang = null,
        public PaymentFlow $flow = PaymentFlow::Payin,
        public ?string $returnUrl = null,
        public ?string $walletRecipientExternalRef = null,
        /**
         * The reliable, server-to-server notification target for this
         * payment — distinct from `returnUrl`. Validated for SSRF safety
         * at the Presentation layer (`SafeOutboundUrlRule`, shared with
         * `POST /v1/webhook-endpoints`) before it ever reaches this DTO.
         */
        public ?string $callbackUrl = null,
    ) {}
}
