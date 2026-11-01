<?php

declare(strict_types=1);

namespace Core\Payment\Contracts;

use DateTimeImmutable;

/**
 * ADR-0088 §2 — `GET /v1/payments?status=&created_from=&created_to=&cursor=&limit=`.
 *
 * `status` is the raw canonical string (see `ListPaymentsCommand`): an
 * unrecognised value is no filter. The project is mandatory — the public
 * API never lists across projects.
 */
final readonly class ListPaymentsByCursorCommand
{
    /**
     * @param DateTimeImmutable|null $createdFrom inclusive
     * @param DateTimeImmutable|null $createdTo exclusive
     */
    public function __construct(
        public string $projectId,
        public ?string $status = null,
        public ?DateTimeImmutable $createdFrom = null,
        public ?DateTimeImmutable $createdTo = null,
        public ?string $cursor = null,
        public int $limit = 50,
    ) {}
}
