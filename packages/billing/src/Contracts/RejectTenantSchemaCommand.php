<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

/**
 * ADR-0122 §2 — le geste de l'opérateur qui **refuse** une déclaration.
 *
 * Le refus est visible : la déclaration sort de l'attente de lecture et
 * ne sera jamais rendue (ADR-0122 §2).
 */
final readonly class RejectTenantSchemaCommand
{
    public function __construct(
        public string $projectId,
        public string $schemaId,
        public string $actor,
        public ?string $correlationId,
    ) {}
}
