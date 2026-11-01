<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

/**
 * The read model of a plan and its versions — never
 * `Core\Billing\Domain\Model\Plan`, which stays internal (G3c).
 *
 * `$status` is a `PlanStatus` value ('draft', 'active', 'archived') and
 * `$visibility` a `PlanVisibility` value ('public', 'private'), both as
 * plain strings: the enums stay in `Domain/` (G3c).
 */
final readonly class PlanView
{
    /**
     * @param list<PlanVersionView> $versions the versions of the plan, oldest first
     */
    public function __construct(
        public string $id,
        public string $projectId,
        public string $code,
        public string $name,
        public ?string $description,
        public string $status,
        public string $visibility,
        public int $sortOrder,
        public array $versions,
        public string $createdAt,
        public string $updatedAt,
    ) {}
}
