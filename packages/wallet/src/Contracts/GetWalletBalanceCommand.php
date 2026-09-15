<?php

declare(strict_types=1);

namespace Core\Wallet\Contracts;

final readonly class GetWalletBalanceCommand
{
    public function __construct(
        public string $walletId,
        public string $requestingProjectId,
    ) {}
}
