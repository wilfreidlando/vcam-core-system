<?php

declare(strict_types=1);

namespace Core\Wallet\Contracts;

use DateTimeImmutable;

/**
 * One movement of a wallet, read from the ledger (ADR-0065). `$type` is
 * `payment` (a confirmed payment credited it), `transfer`, `withdrawal` or
 * `debit` (an internal debit). `$reference`, `$description` and
 * `$metadata` are the caller's own, as sent with the transfer or
 * withdrawal; `$paymentId` is set for a payment.
 */
final readonly class WalletTransactionView
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public int $id,
        public DateTimeImmutable $occurredAt,
        public string $direction,
        public int $amountMinor,
        public string $currency,
        public string $type,
        public ?string $operationId,
        public ?string $paymentId,
        public ?string $reference,
        public ?string $description,
        public array $metadata,
    ) {}
}
