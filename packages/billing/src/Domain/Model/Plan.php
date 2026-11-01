<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Model;

use DateTimeImmutable;

/**
 * ADR-0071 §1 — a plan is stable; its **versions** carry the terms.
 *
 * The code is chosen by the project and never changes: an archived plan
 * keeps its code, so the subscriptions that reference it stay readable.
 */
final class Plan
{
    public function __construct(
        public readonly string $id,
        public readonly string $projectId,
        public readonly string $code,
        public string $name,
        public ?string $description,
        public PlanStatus $status,
        public PlanVisibility $visibility,
        public int $sortOrder,
        public readonly DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}

    /**
     * A plan goes from `draft` to `active` when its first version is
     * published — before that it is not proposed to anyone.
     */
    public function activate(DateTimeImmutable $at): void
    {
        $this->status = PlanStatus::Active;
        $this->updatedAt = $at;
    }

    /**
     * ADR-0071 §5 — an archived plan accepts no new subscription; the
     * ones already running continue until their end.
     */
    public function archive(DateTimeImmutable $at): void
    {
        $this->status = PlanStatus::Archived;
        $this->updatedAt = $at;
    }

    public function isArchived(): bool
    {
        return $this->status === PlanStatus::Archived;
    }

    public function updateDetails(string $name, ?string $description, int $sortOrder, DateTimeImmutable $at): void
    {
        $this->name = $name;
        $this->description = $description;
        $this->sortOrder = $sortOrder;
        $this->updatedAt = $at;
    }
}
