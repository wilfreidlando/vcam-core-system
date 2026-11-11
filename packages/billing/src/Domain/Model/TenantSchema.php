<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Model;

use Core\Billing\Domain\Exception\InvalidTenantSchema;
use Core\Billing\Domain\Exception\TenantSchemaNotPending;
use DateTimeImmutable;

/**
 * ADR-0122 §1, §2 — la déclaration de la forme du locataire d'un projet.
 *
 * L'application **propose** une forme ; l'opérateur l'**accepte**. Seule
 * une déclaration acceptée est en force : c'est la seule que le Core rend
 * et applique.
 *
 * Une déclaration est une **forme**, jamais des valeurs :
 *
 * - `uniqueKey` — l'attribut que l'application désigne (chez elle) ;
 * - `uniqueKeyPattern` — le motif que doit respecter la référence du Core
 *   pour devenir cette clé (ADR-0122 §6) ;
 * - `fields` — les clés d'attribut, leur libellé, leur type, leur
 *   obligation, leur longueur maximale et leur source.
 *
 * Les bornes : **32 champs** au plus, **4 KiB** au plus pour la forme
 * sérialisée — les mêmes plafonds que les métadonnées d'un client
 * (ADR-0070 §1). Une déclaration est une fiche, pas un document.
 */
final class TenantSchema
{
    /**
     * Le nombre de champs qu'une déclaration porte au plus.
     */
    public const MAX_FIELDS = 32;

    /**
     * Le poids de la forme sérialisée, en octets.
     */
    public const MAX_FIELDS_BYTES = 4096;

    /**
     * @param list<TenantSchemaField> $fields
     */
    public function __construct(
        public readonly string $id,
        public readonly string $projectId,
        public readonly int $version,
        public TenantSchemaStatus $status,
        public readonly string $uniqueKey,
        public readonly TenantSchemaPattern $uniqueKeyPattern,
        public array $fields,
        public ?DateTimeImmutable $acceptedAt,
        public readonly DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $uniqueKey) !== 1) {
            throw InvalidTenantSchema::becauseBadUniqueKey($uniqueKey);
        }

        if ($version < 1) {
            throw InvalidTenantSchema::becauseBadVersion($version);
        }

        if (count($fields) > self::MAX_FIELDS) {
            throw InvalidTenantSchema::becauseTooManyFields(count($fields));
        }

        $keys = [];
        foreach ($fields as $field) {
            if (isset($keys[$field->key])) {
                throw InvalidTenantSchema::becauseDuplicateFieldKey($field->key);
            }

            $keys[$field->key] = true;
        }

        $encoded = json_encode($this->fieldsAsArray());
        $bytes = is_string($encoded) ? strlen($encoded) : 0;

        if ($bytes > self::MAX_FIELDS_BYTES) {
            throw InvalidTenantSchema::becauseFieldsTooLarge($bytes);
        }
    }

    /**
     * La déclaration est en force : l'opérateur l'a lue et l'a acceptée.
     */
    public function accept(DateTimeImmutable $at): void
    {
        if (! $this->isPending()) {
            throw TenantSchemaNotPending::becauseStatus($this->status->value);
        }

        $this->status = TenantSchemaStatus::Accepted;
        $this->acceptedAt = $at;
        $this->updatedAt = $at;
    }

    /**
     * La déclaration n'est plus en force : elle a été remplacée par une
     * déclaration plus récente, ou refusée par l'opérateur.
     */
    public function supersede(DateTimeImmutable $at): void
    {
        $this->status = TenantSchemaStatus::Superseded;
        $this->updatedAt = $at;
    }

    public function isPending(): bool
    {
        return $this->status === TenantSchemaStatus::Pending;
    }

    public function isAccepted(): bool
    {
        return $this->status === TenantSchemaStatus::Accepted;
    }

    /**
     * Le champ déclaré sous cette clé, s'il existe.
     */
    public function field(string $key): ?TenantSchemaField
    {
        foreach ($this->fields as $field) {
            if ($field->key === $key) {
                return $field;
            }
        }

        return null;
    }

    /**
     * La forme JSON des champs — celle que porte la colonne `fields`.
     *
     * @return list<array{key: string, label: string, type: string, required: bool, max_length: int|null, source: string}>
     */
    public function fieldsAsArray(): array
    {
        $fields = [];

        foreach ($this->fields as $field) {
            $fields[] = $field->toArray();
        }

        return $fields;
    }
}
