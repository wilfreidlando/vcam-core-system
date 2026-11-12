<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

/**
 * ADR-0122 §2 — le geste de l'opérateur qui **accepte** une déclaration.
 *
 * Le projet et l'identifiant de la déclaration voyagent ensemble : une
 * déclaration qui n'appartient pas au projet est **introuvable**, jamais
 * refusée (ADR-0069 §2).
 */
final readonly class AcceptTenantSchemaCommand
{
    public function __construct(
        public string $projectId,
        public string $schemaId,
        public string $actor,
        public ?string $correlationId,
    ) {}
}
