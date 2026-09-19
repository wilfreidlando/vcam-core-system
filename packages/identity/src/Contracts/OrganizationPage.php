<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

final readonly class OrganizationPage
{
    /**
     * @param list<OrganizationView> $items
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $page,
        public int $perPage,
    ) {}
}
