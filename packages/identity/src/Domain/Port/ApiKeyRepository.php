<?php

declare(strict_types=1);

namespace Core\Identity\Domain\Port;

use Core\Identity\Domain\Model\ApiKey;

interface ApiKeyRepository
{
    public function save(ApiKey $apiKey): void;

    public function find(string $id): ?ApiKey;

    /**
     * The only lookup the authentication path needs: find the (still active
     * or not — the caller decides what to do with a revoked one) key for a
     * given visible prefix. `prefix` is UNIQUE, so this never needs to rank
     * candidates.
     */
    public function findByPrefix(string $prefix): ?ApiKey;

    /**
     * Newest first — the operator console's self-service listing. A
     * Project's own key count stays small in practice, so this is never
     * paginated, unlike operators/payments.
     *
     * @return list<ApiKey>
     */
    public function findAllByProject(string $projectId): array;
}
