<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

/**
 * ADR-0122 §2 — la déclaration de forme que l'application propose.
 *
 * `$fields` porte des **noms**, jamais des valeurs : la clé, le libellé,
 * le type, l'obligation, la longueur maximale et la source de chaque
 * champ. `$actor` et `$correlationId` portent le geste : c'est l'opérateur
 * qui décide ensuite (ADR-0122 §2).
 */
final readonly class ProposeTenantSchemaCommand
{
    /**
     * @param list<array{key: string, label: string, type: string, required: bool, max_length: int|null, source: string}> $fields
     */
    public function __construct(
        public string $projectId,
        public string $uniqueKey,
        public string $uniqueKeyPattern,
        public array $fields,
        public string $actor,
        public ?string $correlationId,
    ) {}
}
