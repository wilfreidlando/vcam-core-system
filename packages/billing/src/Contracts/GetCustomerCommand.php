<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

/**
 * ADR-0070 §2 — the customer to read, by the reference the project knows
 * it by.
 */
final readonly class GetCustomerCommand
{
    public function __construct(
        public string $projectId,
        public string $externalRef,
    ) {}
}
