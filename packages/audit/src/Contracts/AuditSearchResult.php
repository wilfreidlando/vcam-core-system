<?php

declare(strict_types=1);

namespace Core\Audit\Contracts;

final readonly class AuditSearchResult
{
    /**
     * @param list<AuditEntryView> $items
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $page,
        public int $perPage,
    ) {}
}
