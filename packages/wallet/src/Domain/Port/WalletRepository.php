<?php

declare(strict_types=1);

namespace Core\Wallet\Domain\Port;

use Core\Wallet\Domain\Model\Wallet;

interface WalletRepository
{
    public function save(Wallet $wallet): void;

    public function find(string $id): ?Wallet;

    public function findByPartyAndCurrency(string $partyId, string $currency): ?Wallet;
}
