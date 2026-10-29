<?php

declare(strict_types=1);

namespace Core\Identity\Domain\Port;

use Core\Identity\Domain\Model\Operator;
use Core\Identity\OperatorRole;

interface OperatorRepository
{
    public function create(Operator $operator): void;

    public function save(Operator $operator): void;

    public function find(string $id): ?Operator;

    public function findByEmail(string $email): ?Operator;

    /**
     * How many accounts hold this platform role and are not revoked.
     */
    public function countActiveWithRole(OperatorRole $role): int;

    /**
     * Newest first — the admin console's self-service listing. `$search`,
     * when given, matches a case-insensitive substring of `email` OR
     * `name`.
     *
     * @param list<string>|null $operatorIds when given, only those
     *                                       operators are listed
     *                                       (ADR-0069 §2 — the console's
     *                                       scope). It is applied in the
     *                                       query, so the operators of
     *                                       another tenant are never
     *                                       materialised; an empty list
     *                                       matches nothing.
     * @return array{items: list<Operator>, total: int}
     */
    public function findAll(int $page, int $perPage, ?string $search = null, ?array $operatorIds = null): array;
}
