<?php

declare(strict_types=1);

namespace App\Http\View;

/**
 * The words the console uses for a declared form.
 *
 * An application declares the shape of its customer: the pattern its
 * references follow, and keys with a label, a type and a source. The Core
 * knows none of them by heart — the console has to say them in French, and
 * to say a pattern in words an operator can read (« AE suivi de 4 à 8
 * chiffres ») rather than in the notation of a developer.
 */
final class TenantSchemaLabels
{
    /**
     * The reference pattern, said in plain French.
     *
     * A shape this does not know is given back as it is: a pattern the
     * operator cannot read in words is better than a wrong translation.
     */
    public static function pattern(string $pattern): string
    {
        $body = (string) preg_replace('/^\^/', '', trim($pattern));
        $body = (string) preg_replace('/\$$/', '', $body);

        // « AE[0-9]{4,8} », « AE-[0-9]{4,8} », « AE[0-9]{4} », « AE[0-9] ».
        if (preg_match('/^([A-Za-z0-9]+)[-_.:]?\[([0-9A-Za-z-]+)\](?:\{(\d+)(?:,(\d+))?\})?$/', $body, $matches) === 1) {
            if (($matches[3] ?? '') === '') {
                return sprintf('%s suivi d\'un %s', $matches[1], self::singular($matches[2]));
            }

            if (($matches[4] ?? '') === '') {
                return sprintf('%s suivi de %s %s', $matches[1], $matches[3], self::plural($matches[2]));
            }

            return sprintf('%s suivi de %s à %s %s', $matches[1], $matches[3], $matches[4], self::plural($matches[2]));
        }

        // « AE » : a code made of letters only.
        if (preg_match('/^([A-Za-z0-9]+)$/', $body, $matches) === 1) {
            return $matches[1];
        }

        return trim($pattern);
    }

    /**
     * The type of a declared field, in French.
     *
     * An unknown type is returned as it is: a new type must be given a
     * word before it is shown, and hiding it would hide the problem too.
     */
    public static function type(string $type): string
    {
        return match ($type) {
            'string' => 'Texte court',
            'text' => 'Texte',
            'integer' => 'Nombre entier',
            'decimal' => 'Nombre décimal',
            'boolean' => 'Oui ou non',
            'date' => 'Date',
            'datetime' => 'Date et heure',
            'email' => 'Adresse électronique',
            'phone' => 'Numéro de téléphone',
            'url' => 'Adresse web',
            default => $type,
        };
    }

    /**
     * The source of a declared field, in French.
     *
     * A source the Core reserves names one of its own identity fields: the
     * declared value then feeds it, and the console never asks the
     * operator for the same value twice.
     */
    public static function source(string $source): string
    {
        return match ($source) {
            'core_identity.display_name' => 'le nom du client',
            'core_identity.email' => 'le contact du client',
            'core_identity.phone' => 'le téléphone du client',
            'core_identity.locale' => 'la langue du client',
            default => 'une valeur déclarée par l\'application',
        };
    }

    /**
     * The Core identity field a source names, or `null` when the source is
     * the application's own value.
     */
    public static function identityField(string $source): ?string
    {
        return match ($source) {
            'core_identity.display_name' => 'display_name',
            'core_identity.email' => 'email',
            'core_identity.phone' => 'phone',
            'core_identity.locale' => 'locale',
            default => null,
        };
    }

    private static function plural(string $class): string
    {
        return match (true) {
            $class === '0-9' => 'chiffres',
            $class === 'A-Z', $class === 'a-z' => 'lettres',
            $class === 'A-Za-z0-9', $class === 'a-z0-9' => 'lettres ou chiffres',
            default => 'caractères',
        };
    }

    private static function singular(string $class): string
    {
        return match (true) {
            $class === '0-9' => 'chiffre',
            $class === 'A-Z', $class === 'a-z' => 'lettre',
            default => 'caractère',
        };
    }
}
