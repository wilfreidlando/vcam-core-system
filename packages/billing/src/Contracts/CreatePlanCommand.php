<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

/**
 * ADR-0071 §1 — the plan as the project names it: a stable `code`, a
 * display `name`, and who it is proposed to.
 *
 * `visibility` is `public` (proposed in the portal) or `private`
 * (attributed only) — a plain string, the enum stays in `Domain/` (G3c).
 */
final readonly class CreatePlanCommand
{
    /**
     * @param string $actor "operator:<uuid>", "project:<uuid>" or "service:<name>" — who asked for the write
     */
    public function __construct(
        public string $projectId,
        public string $code,
        public string $name,
        public ?string $description,
        public string $visibility = 'public',
        public int $sortOrder = 0,
        public string $actor = '',
        public ?string $correlationId = null,
    ) {}
}
