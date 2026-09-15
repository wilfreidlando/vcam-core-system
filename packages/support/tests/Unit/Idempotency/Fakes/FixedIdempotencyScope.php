<?php

declare(strict_types=1);

namespace Core\Support\Tests\Unit\Idempotency\Fakes;

use Core\Support\Idempotency\IdempotencyScope;

final class FixedIdempotencyScope implements IdempotencyScope
{
    public function __construct(
        private readonly string $scope = 'project-1',
    ) {}

    public function current(): string
    {
        return $this->scope;
    }
}
