<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

/**
 * ADR-0073 §2, §4 — the settlements of one customer of one project.
 *
 * The customer is named by the reference the project knows it by (the
 * correlation key of ADR-0070 §1) — never by an id that would say
 * nothing to the caller.
 */
final readonly class ListCustomerSettlementsCommand
{
    public function __construct(
        public string $projectId,
        public string $customerRef,
        public ?string $cursor = null,
        public int $limit = 50,
    ) {}
}
