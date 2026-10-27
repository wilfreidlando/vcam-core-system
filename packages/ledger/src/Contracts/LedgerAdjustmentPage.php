<?php

declare(strict_types=1);

namespace Core\Ledger\Contracts;

final readonly class LedgerAdjustmentPage
{
    /**
     * @param list<LedgerAdjustmentView> $items
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $page,
        public int $perPage,
    ) {}
}
