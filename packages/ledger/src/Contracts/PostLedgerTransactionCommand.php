<?php

declare(strict_types=1);

namespace Core\Ledger\Contracts;

final readonly class PostLedgerTransactionCommand
{
    /**
     * @param list<LedgerEntryInput> $entries
     */
    public function __construct(
        public string $projectId,
        public string $type,
        public ?string $externalRef,
        public string $idempotencyKey,
        public array $entries,
    ) {}
}
