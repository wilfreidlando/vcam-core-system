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
     * Newest first (ADR-0045 — the operator console's project-scoped
     * listing).
     *
     * @return array{items: list<Wallet>, total: int}
     */
    public function findByProject(string $projectId, int $page, int $perPage): array;
}
