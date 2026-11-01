<?php

declare(strict_types=1);

namespace Core\Ledger\Contracts;

final readonly class LedgerTransactionView
{
    /**
     * @param list<LedgerTransactionLine> $lines debits first
     */
    public function __construct(
        public string $id,
        public string $projectId,
        public string $type,
        public ?string $externalRef,
        public string $idempotencyKey,
        public ?string $reversesTransactionId,
        public string $createdAt,
        public array $lines,
    ) {}
}
