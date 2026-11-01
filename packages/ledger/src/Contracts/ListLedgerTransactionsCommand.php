<?php

declare(strict_types=1);

namespace Core\Ledger\Contracts;

use DateTimeImmutable;

final readonly class ListLedgerTransactionsCommand
{
    /**
     * @param DateTimeImmutable|null $from inclusive
     * @param DateTimeImmutable|null $to exclusive
     * @param list<string>|null $types only these transaction types; `null` = all
     */
    public function __construct(
        public string $projectId,
        public ?DateTimeImmutable $from = null,
        public ?DateTimeImmutable $to = null,
        public ?array $types = null,
        public ?string $cursor = null,
        public int $limit = 50,
    ) {}
}
