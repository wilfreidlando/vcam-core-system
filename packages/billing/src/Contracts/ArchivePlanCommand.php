<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

/**
 * ADR-0071 §5 — `POST /v1/plans/{code}/archive`.
 */
final readonly class ArchivePlanCommand
{
    /**
     * @param string $actor "operator:<uuid>", "project:<uuid>" or "service:<name>" — who asked for the write
     */
    public function __construct(
        public string $projectId,
        public string $planCode,
        public string $actor = '',
        public ?string $correlationId = null,
    ) {}
}
