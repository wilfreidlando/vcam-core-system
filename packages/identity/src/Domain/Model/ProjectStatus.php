<?php

declare(strict_types=1);

namespace Core\Identity\Domain\Model;

/**
 * ADR-0069 §3 — the lifecycle of a Project.
 *
 * - `active`: normal operation.
 * - `suspended`: every /v1 request of the project is refused
 *   (403 project-suspended); the provider webhooks that already carry
 *   money keep being processed — the cash in flight does not stop.
 * - `archived`: final state. Keys revoked, webhook endpoints disabled,
 *   data kept per ADR-0083.
 */
enum ProjectStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Archived = 'archived';

    /**
     * The transitions the model allows. `archived` is final.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            // ADR-0069 §3: `active ⇄ suspended`, `active|suspended → archived`.
            self::Active => [self::Suspended, self::Archived],
            self::Suspended => [self::Active, self::Archived],
            self::Archived => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
