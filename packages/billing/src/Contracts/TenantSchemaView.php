<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

/**
 * ADR-0122 §2 — une déclaration de forme, en lecture.
 *
 * `$status` est la valeur de l'énumération `TenantSchemaStatus`
 * (`pending`, `accepted`, `superseded`) — jamais l'énumération
 * elle-même (G3c). `$fields` porte la forme : des noms, jamais des
 * valeurs (ADR-0122 §1).
 */
final readonly class TenantSchemaView
{
    /**
     * @param list<TenantSchemaFieldView> $fields
     */
    public function __construct(
        public string $id,
        public string $projectId,
        public int $version,
        public string $status,
        public string $uniqueKey,
        public string $uniqueKeyPattern,
        public array $fields,
        public ?string $acceptedAt,
        public string $createdAt,
        public string $updatedAt,
    ) {}
}
