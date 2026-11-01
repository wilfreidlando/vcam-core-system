<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

/**
 * ADR-0071 §2 — `POST /v1/plans/{code}/versions/{n}/publish`.
 */
final readonly class PublishPlanVersionCommand
{
    /**
     * @param string $actor "operator:<uuid>", "project:<uuid>" or "service:<name>" — who asked for the write
     */
    public function __construct(
        public string $projectId,
        public string $planCode,
        public int $version,
        public string $actor = '',
        public ?string $correlationId = null,
    ) {}
}
