<?php

declare(strict_types=1);

namespace Core\Ledger\Contracts;

final readonly class PostLedgerTransactionCommand
{
    /**
     * @param list<LedgerEntryInput> $entries
     * @param string|null $reversesTransactionId ADR-0076 §5 — the transaction this one counter-passes. Only an operating correction carries it. Optional and last: a caller that does not know about it books an ordinary transaction.
     */
    public function __construct(
        public string $projectId,
        public string $type,
        public ?string $externalRef,
        public string $idempotencyKey,
        public array $entries,
        public ?string $reversesTransactionId = null,
    ) {}
}
