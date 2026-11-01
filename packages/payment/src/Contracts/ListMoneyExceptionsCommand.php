<?php

declare(strict_types=1);

namespace Core\Payment\Contracts;

final readonly class ListMoneyExceptionsCommand
{
    /**
     * @param list<string>|null $projectIds `null` = every project (platform-wide reader only)
     * @param string|null $status `open` | `awaiting_approval` | `resolved`; `null` lists every status
     */
    public function __construct(
        public ?array $projectIds = null,
        public ?string $status = null,
        public int $page = 1,
        public int $perPage = 25,
    ) {}
}
