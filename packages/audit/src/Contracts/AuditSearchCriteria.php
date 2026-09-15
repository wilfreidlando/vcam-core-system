<?php

declare(strict_types=1);

namespace Core\Audit\Contracts;

use DateTimeImmutable;

/**
 * All filters are optional (`null` = unfiltered on that field) and
 * combined with AND. `page` is 1-based.
 */
final readonly class AuditSearchCriteria
{
    public function __construct(
        public ?string $resource = null,
        public ?string $actor = null,
        public ?string $action = null,
        public ?string $correlationId = null,
        public ?DateTimeImmutable $from = null,
        public ?DateTimeImmutable $to = null,
        public int $page = 1,
        public int $perPage = 25,
    ) {}
}
