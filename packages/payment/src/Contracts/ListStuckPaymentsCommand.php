<?php

declare(strict_types=1);

namespace Core\Payment\Contracts;

final readonly class ListStuckPaymentsCommand
{
    public function __construct(
        public int $limit,
    ) {}
}
