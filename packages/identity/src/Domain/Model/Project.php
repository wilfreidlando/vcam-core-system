<?php

declare(strict_types=1);

namespace Core\Identity\Domain\Model;

use Core\Identity\Domain\Exception\InvalidProjectStatusTransition;
use Core\Identity\Domain\Exception\ProjectStatusChangeRequiresReason;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A concrete application, platform or integration consuming the Core. It is
 * the Project that authenticates to the Core (spec fonctionnelle §4.2) — not
 * the organization, and never the organization's end-users.
 */
final class Project
{
    public function __construct(
        public readonly string $id,
        public readonly string $organizationId,
        private string $name,
        private string $status,
        // ADR-0069 §3 — when the project was suspended or archived, and
        // why. Kept on the row: the audit trail also records it, but the
        // project must be able to explain its own state.
        public readonly ?DateTimeImmutable $statusChangedAt = null,
        public readonly ?string $statusReason = null,
    ) {
        if (trim($name) === '') {
            throw new InvalidArgumentException('A project name cannot be blank.');
        }
    }

    public static function create(string $id, string $organizationId, string $name): self
    {
        return new self($id, $organizationId, $name, 'active');
    }

    public function name(): string
    {
        return $this->name;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }

    public function isArchived(): bool
    {
        return $this->status === 'archived';
    }

    /**
     * ADR-0069 §3 — a transition, with its reason. The reason is mandatory:
     * a suspension or an archiving is an administrative decision, and the
     * audit trail keeps it.
     */
    public function changeStatus(ProjectStatus $target, DateTimeImmutable $at, string $reason): self
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ProjectStatusChangeRequiresReason::forReason();
        }

        $current = ProjectStatus::tryFrom($this->status);
        if ($current === null || ! $current->canTransitionTo($target)) {
            throw InvalidProjectStatusTransition::fromTo($this->status, $target->value);
        }

        return new self(
            $this->id,
            $this->organizationId,
            $this->name,
            $target->value,
            $at,
            mb_substr($reason, 0, 500),
        );
    }
}
