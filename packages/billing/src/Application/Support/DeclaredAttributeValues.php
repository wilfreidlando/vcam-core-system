<?php

declare(strict_types=1);

namespace Core\Billing\Application\Support;

use Core\Billing\Domain\Exception\CustomerDeclaredAttributeRefused;
use Core\Billing\Domain\Model\TenantSchema;

/**
 * ADR-0122 §5, ADR-0130 §1 — les valeurs déclarées, confrontées à la
 * forme en force.
 *
 * La règle est **une seule**, partagée par les deux gestes qui écrivent
 * des valeurs déclarées : le cas d'usage d'upsert (qui les écrit avant
 * d'émettre l'événement) et le cas d'usage qui les remplace ensuite.
 *
 * Une valeur vide retire la clé : elle ne vaut pas une valeur.
 */
final class DeclaredAttributeValues
{
    /**
     * @param array<string, string> $values
     * @return array<string, string>
     */
    public static function validated(TenantSchema $schema, array $values): array
    {
        $validated = [];

        foreach ($values as $key => $value) {
            $field = $schema->field($key);

            if ($field === null) {
                throw CustomerDeclaredAttributeRefused::becauseKeyNotDeclared($key);
            }

            // ADR-0130 §1 — un champ dont la source est l'identité du Core
            // ne vit pas dans les attributs déclarés : sa valeur est dans
            // l'identité du client, chiffrée avec elle (ADR-0083 §3). On
            // l'ignore ici — et on ne l'exige pas plus bas.
            if ($field->isCoreIdentity()) {
                continue;
            }

            $trimmed = trim($value);

            if ($trimmed === '') {
                if ($field->required) {
                    throw CustomerDeclaredAttributeRefused::becauseRequiredValueMissing($key);
                }

                continue;
            }

            if ($field->maxLength !== null && mb_strlen($trimmed) > $field->maxLength) {
                throw CustomerDeclaredAttributeRefused::becauseValueTooLong($key, $field->maxLength);
            }

            $validated[$key] = $trimmed;
        }

        // Les champs obligatoires doivent être là, même quand l'opérateur
        // ne les a pas renvoyés.
        foreach ($schema->fields as $field) {
            if ($field->isCoreIdentity()) {
                continue;
            }

            if ($field->required && ! isset($validated[$field->key])) {
                throw CustomerDeclaredAttributeRefused::becauseRequiredValueMissing($field->key);
            }
        }

        return $validated;
    }
}
