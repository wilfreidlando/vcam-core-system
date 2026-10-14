<?php

declare(strict_types=1);

namespace Core\Wallet\Contracts;

final readonly class ListWalletTransactionsCommand
{
    public function __construct(
        public string $projectId,
        public string $environment,
        public string $walletId,
        public int $page = 1,
        public int $perPage = 25,
    ) {}
}
