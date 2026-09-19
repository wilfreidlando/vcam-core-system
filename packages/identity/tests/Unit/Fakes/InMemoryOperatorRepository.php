<?php

declare(strict_types=1);

namespace Core\Identity\Tests\Unit\Fakes;

use Core\Identity\Domain\Model\Operator;
use Core\Identity\Domain\Port\OperatorRepository;

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

    public function findByEmail(string $email): ?Operator
    {
        foreach ($this->operators as $operator) {
            if ($operator->email === $email) {
                return $operator;
            }
        }

        return null;
    }

    public function findAll(int $page, int $perPage): array
    {
        $all = array_values($this->operators);
        $total = count($all);
        $offset = ($page - 1) * $perPage;

        return ['items' => array_slice($all, $offset, $perPage), 'total' => $total];
    }
}
