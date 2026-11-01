<?php

declare(strict_types=1);

namespace Core\Wallet\Presentation\Http\Requests\Concerns;

/**
 * Cast des nombres reçus en chaîne, AVANT la validation (F-12).
 *
 * La règle Laravel `integer` valide (`filter_var($v, FILTER_VALIDATE_INT)`)
 * mais ne caste pas : `"5000"` passe, `validated()` rend la chaîne, et la
 * commande typée `int` lève une `TypeError` en mode strict (HTTP 500 au lieu
 * de 422). Idem `boolean`, qui accepte `'0'` et `'1'`.
 *
 * Le cast se fait donc une seule fois, ici, de sorte que `validated()` rende
 * déjà des types corrects. Une valeur qui n'est pas un entier / booléen valide
 * reste TELLE QUELLE : la règle (`integer:strict`, `boolean`) la refuse alors
 * en 422. Un trait par module : le graphe de modules (G3c) interdit de partager
 * du code de présentation entre billing, payment et wallet.
 */
trait CastsNumericInput
{
    /**
     * Le corps de la requête (hors fichiers), garanti tableau pour l'analyse statique.
     *
     * @return array<array-key, mixed>
     */
    protected function inputAsArray(): array
    {
        $input = $this->input();

        return is_array($input) ? $input : [];
    }

    /**
     * Les clés de premier niveau de `$row` à caster, uniquement celles présentes.
     *
     * @param array<array-key, mixed> $row
     * @param list<string> $integers clés attendues entières
     * @param list<string> $booleans clés attendues booléennes
     * @return array<string, mixed>
     */
    protected static function castNumericKeys(array $row, array $integers, array $booleans = []): array
    {
        $cast = [];

        foreach ($integers as $key) {
            if (array_key_exists($key, $row)) {
                $cast[$key] = self::integerOrSame($row[$key]);
            }
        }

        foreach ($booleans as $key) {
            if (array_key_exists($key, $row)) {
                $cast[$key] = self::booleanOrSame($row[$key]);
            }
        }

        return $cast;
    }

    /**
     * `"5000"` devient `5000` ; `"abc"`, `"12.5"`, `true`, un tableau… restent
     * inchangés pour que la validation les refuse.
     */
    private static function integerOrSame(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $int = filter_var($value, FILTER_VALIDATE_INT);

        return $int === false ? $value : $int;
    }

    /**
     * Les seules valeurs que la règle `boolean` accepte en plus de `true` / `false`.
     */
    private static function booleanOrSame(mixed $value): mixed
    {
        return match ($value) {
            1, '1' => true,
            0, '0' => false,
            default => $value,
        };
    }
}
