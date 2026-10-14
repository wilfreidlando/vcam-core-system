<?php

declare(strict_types=1);

namespace Core\Wallet\Contracts;

interface ListPartyWallets
{
    /**
     * A party that does not exist yet has no wallet: an empty list, never
     * an error — and never a party created as a side effect of a read.
     *
     * @return list<PartyWalletView>
     */
    public function list(ListPartyWalletsCommand $command): array;
}
