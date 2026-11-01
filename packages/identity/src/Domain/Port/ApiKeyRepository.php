<?php

declare(strict_types=1);

namespace Core\Identity\Domain\Port;

use Core\Identity\Domain\Model\ApiKey;
use DateTimeImmutable;

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

    /**
     * ADR-0082 §1 — keys that count against the per-project limit: every
     * key that still authenticates at `$now` (not revoked, not expired),
     * including a replaced key still in its grace period. Call inside a
     * transaction after `lockProject()`.
     */
    public function countActiveByProject(string $projectId, DateTimeImmutable $now): int;

    /**
     * Serialises issuance for one project until the end of the current
     * transaction, so two concurrent issuances cannot both pass the limit.
     */
    public function lockProject(string $projectId): void;

    /**
     * ADR-0060 — revoking deletes the key; the audit trail keeps the record.
     */
    public function delete(string $id): void;

    /**
     * Records that the key was just used, and from where — at most once a
     * minute, unless the address changed (cheap enough to run on every
     * authenticated request).
     */
    public function touchLastUsed(string $id, DateTimeImmutable $at, ?string $ip = null): void;
}
