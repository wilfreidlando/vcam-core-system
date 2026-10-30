<?php

declare(strict_types=1);

namespace Core\Identity\Tests\Unit\Fakes;

use Core\Identity\Domain\Model\Operator;
use Core\Identity\Domain\Port\OperatorRepository;
use Core\Identity\OperatorRole;

final class InMemoryOperatorRepository implements OperatorRepository
{
    /** @var array<string, Operator> */
    private array $operators = [];

    public function create(Operator $operator): void
    {
        $this->operators[$operator->id] = $operator;
    }

    public function save(Operator $operator): void
    {
        $this->operators[$operator->id] = $operator;
    }

    public function find(string $id): ?Operator
    {
        return $this->operators[$id] ?? null;
    }

    public function countActiveWithRole(OperatorRole $role): int
    {
        return count(array_filter($this->operators, static fn (Operator $operator): bool => $operator->role === $role && ! $operator->isRevoked()));
    }

    public function lockActiveWithRole(OperatorRole $role): int
    {
        // The in-memory fake has no rows to lock: the count is the same.
        // The real guarantee (serialization) belongs to the Eloquent
        // implementation; what this fake must reproduce is the refusal
        // of the last administrator, which it does.
        return $this->countActiveWithRole($role);
    }

    public function findByEmail(string $email): ?Operator
    {
        // ADR-0069 §1 — case-insensitive, exactly like the Eloquent
        // repository: the double must not be more permissive (nor more
        // strict) than the production one.
        $needle = strtolower(trim($email));

        foreach ($this->operators as $operator) {
            if (strtolower(trim($operator->email)) === $needle) {
                return $operator;
            }
        }

        return null;
    }

    public function findAll(int $page, int $perPage, ?string $search = null, ?array $operatorIds = null): array
    {
        $all = array_values($this->operators);

        if ($operatorIds !== null) {
            // ADR-0069 §2 — the scope filters the listing: an operator
            // outside the scope is never returned, and an empty scope
            // matches nothing.
            $all = array_values(array_filter(
                $all,
                static fn (Operator $operator): bool => in_array($operator->id, $operatorIds, true),
            ));
        }

        if ($search !== null && $search !== '') {
            $all = array_values(array_filter(
                $all,
                static fn (Operator $operator): bool => stripos($operator->email, $search) !== false
                    || stripos($operator->name, $search) !== false,
            ));
        }

        $total = count($all);
        $offset = ($page - 1) * $perPage;

        return ['items' => array_slice($all, $offset, $perPage), 'total' => $total];
    }
}
