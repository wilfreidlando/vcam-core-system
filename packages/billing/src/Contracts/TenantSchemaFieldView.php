<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

/**
 * ADR-0122 §3 — la forme d'un champ déclaré, en lecture.
 *
 * Le Core ne connaît que des **noms** : la clé, le libellé, le type,
 * l'obligation, la longueur maximale et la source. `$type` est la valeur
 * de l'énumération `TenantSchemaFieldType` — jamais l'énumération
 * elle-même (G3c).
 */
final readonly class TenantSchemaFieldView
{
    public function __construct(
        public string $key,
        public string $label,
        public string $type,
        public bool $required,
        public ?int $maxLength,
        public string $source,
    ) {}
}
