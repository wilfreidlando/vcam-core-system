<?php

declare(strict_types=1);

namespace Core\Wallet\Contracts;

final readonly class WalletTransactionPage
{
    /**
     * @param list<WalletTransactionView> $items Newest first.
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $page,
        public int $perPage,
    ) {}
}
