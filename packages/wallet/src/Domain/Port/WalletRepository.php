<?php

declare(strict_types=1);

namespace Core\Wallet\Domain\Port;

use Core\Wallet\Domain\Model\Wallet;

interface WalletRepository
{
    public function save(Wallet $wallet): void;

    public function find(string $id): ?Wallet;

    public function findByPartyAndCurrency(string $partyId, string $currency): ?Wallet;

    /**
     * Every wallet of a party, one per currency, ordered by currency.
     *
     * @return list<Wallet>
     */
    public function findByParty(string $partyId): array;

    /**
     * Newest first (ADR-0045 — the operator console's project-scoped
     * listing). `$search`, when given, matches a case-insensitive
     * substring of `partyId` OR `currency` — the only two identifying
     * fields a wallet carries (it has no name of its own).
     *
     * @return array{items: list<Wallet>, total: int}
     */
    public function findByProject(string $projectId, int $page, int $perPage, ?string $search = null): array;
}
