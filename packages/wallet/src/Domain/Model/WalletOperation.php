<?php

declare(strict_types=1);

namespace Core\Wallet\Domain\Model;

use DateTimeImmutable;

/**
 * A transfer or a withdrawal requested by a product (ADR-0065): what the
 * ledger does not carry — the caller's idempotency key, its description,
 * its opaque metadata and the legs as requested. Amounts and balances stay
 * the ledger's; `ledgerTransactionId` is the posting that justifies them.
 */
final readonly class WalletOperation
{
    public const TRANSFER = 'transfer';

    public const WITHDRAWAL = 'withdrawal';

    /**
     * @param list<array{from: string, to: string|null, amount_minor: int}> $legs
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $id,
        public string $projectId,
        public string $environment,
        public string $type,
        public string $idempotencyKey,
        public string $currency,
        public int $totalMinor,
        public ?string $description,
        public array $metadata,
        public array $legs,
        public ?string $externalReference,
        public string $ledgerTransactionId,
        public DateTimeImmutable $createdAt,
    ) {}
}
