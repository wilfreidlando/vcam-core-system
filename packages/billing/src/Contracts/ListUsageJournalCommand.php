<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

use DateTimeImmutable;

/**
 * ADR-0088 §2 — `GET /v1/usage?meter=&customer_ref=&created_from=&created_to=&cursor=&limit=`.
 */
final readonly class ListUsageJournalCommand
{
    /**
     * @param DateTimeImmutable|null $createdFrom inclusive
     * @param DateTimeImmutable|null $createdTo exclusive
     */
    public function __construct(
        public string $projectId,
        public ?string $meter = null,
        public ?string $customerRef = null,
        public ?DateTimeImmutable $createdFrom = null,
        public ?DateTimeImmutable $createdTo = null,
        public ?string $cursor = null,
        public int $limit = 50,
    ) {}
}
