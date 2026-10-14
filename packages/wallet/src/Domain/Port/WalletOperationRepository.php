<?php

declare(strict_types=1);

namespace Core\Wallet\Domain\Port;

use Core\Wallet\Domain\Exception\WalletOperationAlreadyExists;
use Core\Wallet\Domain\Model\WalletOperation;

interface WalletOperationRepository
{
    /**
     * @throws WalletOperationAlreadyExists the (project, idempotency key) is taken
     */
    public function save(WalletOperation $operation): void;

    public function findByIdempotencyKey(string $projectId, string $idempotencyKey): ?WalletOperation;

    /**
     * Bulk read for a transactions page: one query, keyed by operation id.
     *
     * @param list<string> $ids
     * @return array<string, WalletOperation>
     */
    public function findMany(array $ids): array;
}
