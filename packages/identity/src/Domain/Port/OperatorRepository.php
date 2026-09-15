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
}
