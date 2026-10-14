<?php

declare(strict_types=1);

namespace Core\Wallet\Contracts;

/**
 * Records that value left the Core's custody (ADR-0065): a payout made
 * outside the Core — a mobile-money transfer, a bank wire — whose proof
 * is `externalReference`. The wallet is debited against the project's
 * `withdrawal` account; no value is created.
 */
final readonly class RecordWithdrawalCommand
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $projectId,
        public string $idempotencyKey,
        public string $party,
        public int $amountMinor,
        public string $currency,
        public ?string $description = null,
        public ?string $externalReference = null,
        public array $metadata = [],
    ) {}
}
