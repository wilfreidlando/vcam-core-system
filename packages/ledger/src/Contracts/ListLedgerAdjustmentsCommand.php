<?php

declare(strict_types=1);

namespace Core\Ledger\Contracts;

final readonly class ListLedgerAdjustmentsCommand
{
    /**
     * @param list<string>|null $projectIds `null` = every project (platform-wide reader only)
     * @param string|null $status `proposed` | `approved` | `rejected`; `null` lists every status
     */
    public function __construct(
        public ?array $projectIds = null,
        public ?string $status = null,
        public int $page = 1,
        public int $perPage = 25,
    ) {}
}
