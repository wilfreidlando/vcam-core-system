<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

/**
 * ADR-0071 §5 — `GET /v1/plans`.
 *
 * `status` is a canonical `PlanStatus` value ('draft', 'active',
 * 'archived') as a plain string — never the enum itself (G3c) — and
 * nullable: `null` lists every status, an unrecognised value is ignored
 * (same convention as `ListWebhookDeliveriesCommand::$status`).
 */
final readonly class ListPlansCommand
{
    public function __construct(
        public string $projectId,
        public ?string $status = null,
        public ?string $cursor = null,
        public int $limit = 50,
    ) {}
}
