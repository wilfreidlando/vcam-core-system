<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

/**
 * ADR-0070 §2 — `POST /v1/customers/{external_ref}/reactivate`.
 */
final readonly class ReactivateCustomerCommand
{
    /**
     * @param string $actor "operator:<uuid>", "project:<uuid>" or "service:<name>" — who asked for the write
     */
    public function __construct(
        public string $projectId,
        public string $externalRef,
        public string $actor,
        public ?string $correlationId,
    ) {}
}
