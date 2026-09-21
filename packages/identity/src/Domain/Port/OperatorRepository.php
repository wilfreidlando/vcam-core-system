<?php

declare(strict_types=1);

namespace Core\Identity\Domain\Port;

use Core\Identity\Domain\Model\Operator;

interface OperatorRepository
{
    public function create(Operator $operator): void;

    public function save(Operator $operator): void;

    public function find(string $id): ?Operator;

    public function findByEmail(string $email): ?Operator;

    /**
     * Newest first — the admin console's self-service listing. `$search`,
     * when given, matches a case-insensitive substring of `email` OR
     * `name`.
     *
     * @return array{items: list<Operator>, total: int}
     */
    public function findAll(int $page, int $perPage, ?string $search = null): array;
}
