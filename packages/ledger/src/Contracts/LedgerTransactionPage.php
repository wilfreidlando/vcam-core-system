<?php

declare(strict_types=1);

namespace Core\Ledger\Contracts;

final readonly class LedgerTransactionPage
{
    /**
     * @param list<LedgerTransactionView> $items newest first
     * @param string|null $nextCursor pass it back to read the next (older) page; `null` = no more
     */
    public function __construct(
        public array $items,
        public ?string $nextCursor,
    ) {}
}
