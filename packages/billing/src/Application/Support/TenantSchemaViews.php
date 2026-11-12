<?php

declare(strict_types=1);

namespace Core\Billing\Application\Support;

use Core\Billing\Contracts\TenantSchemaFieldView;
use Core\Billing\Contracts\TenantSchemaView;
use Core\Billing\Domain\Model\TenantSchema;
use Core\Billing\Domain\Model\TenantSchemaField;

/**
 * Le mapping de la déclaration du domaine vers la vue de `Contracts/`
 * (G3c — le modèle du domaine ne quitte jamais le module).
 */
final readonly class TenantSchemaViews
{
    public static function from(TenantSchema $schema): TenantSchemaView
    {
        return new TenantSchemaView(
            id: $schema->id,
            projectId: $schema->projectId,
            version: $schema->version,
            status: $schema->status->value,
            uniqueKey: $schema->uniqueKey,
            uniqueKeyPattern: $schema->uniqueKeyPattern->pattern,
            fields: array_map(self::field(...), $schema->fields),
            acceptedAt: Iso8601::ofNullable($schema->acceptedAt),
            createdAt: Iso8601::of($schema->createdAt),
            updatedAt: Iso8601::of($schema->updatedAt),
        );
    }

    private static function field(TenantSchemaField $field): TenantSchemaFieldView
    {
        return new TenantSchemaFieldView(
            key: $field->key,
            label: $field->label,
            type: $field->type->value,
            required: $field->required,
            maxLength: $field->maxLength,
            source: $field->source,
        );
    }
}
