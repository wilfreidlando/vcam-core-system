<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Model;

use Core\Billing\Domain\Exception\InvalidTenantSchema;

/**
 * ADR-0122 §6 — le motif que doit respecter la référence du Core.
 *
 * La référence d'un client est l'alphabet du Core (ADR-0070 §1) :
 * `^[A-Za-z0-9._:-]{1,128}$`. Le motif déclaré par l'application décrit
 * un **sous-ensemble** de cet alphabet : il ne peut donc jamais autoriser
 * une référence que le Core refuserait.
 *
 * Pour le garantir, le motif n'accepte qu'une syntaxe restreinte :
 *
 * - les littéraux de l'alphabet du Core ;
 * - les classes de caractères **positives** dont toutes les lettres sont de
 *   l'alphabet (`[A-Z]`, `[0-9]`, `[-_.]`) ;
 * - les groupes, les alternatives et les quantificateurs ;
 * - les ancres `^` (en tête) et `$` (en fin).
 *
 * Sont refusés : le joker `.` (il matcherait n'importe quoi), les classes
 * négatives (`[^…]`), les échappages (`\d`, `\w`, `\s`…), les groupes
 * étendus (`(?i)`, `(?P<x>)`) et tout caractère hors de l'alphabet.
 *
 * Le motif est appliqué sur la **référence entière** : `matches()` ancre
 * donc le motif, qu'il porte ou non ses propres ancres.
 */
final readonly class TenantSchemaPattern
{
    /**
     * L'alphabet d'une référence du Core (ADR-0070 §1).
     */
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789._:-';

    /**
     * La longueur maximale du motif lui-même : c'est une **forme**, pas une
     * donnée (ADR-0122 §1).
     */
    private const MAX_LENGTH = 256;

    private function __construct(
        public string $pattern,
    ) {}

    /**
     * Un motif valide : il ne décrit que des références du Core.
     *
     * @throws InvalidTenantSchema le motif est vide, trop long, n'est pas
     *                             une expression régulière, ou décrit des
     *                             caractères hors de l'alphabet du Core
     */
    public static function of(string $pattern): self
    {
        $trimmed = trim($pattern);

        if ($trimmed === '') {
            throw InvalidTenantSchema::becauseBadPattern($pattern, 'le motif est vide');
        }

        if (mb_strlen($trimmed) > self::MAX_LENGTH) {
            throw InvalidTenantSchema::becauseBadPattern(
                $pattern,
                sprintf('le motif dépasse %d caractères', self::MAX_LENGTH),
            );
        }

        // The alphabet first: it refuses every character outside it — the
        // `~` delimiter of the compilation below included. The pattern is
        // **bare**: it carries no delimiter of its own, and it is applied
        // to the whole reference (`matches()`).
        self::assertOnlyDescribesCoreReferences($trimmed);

        if (@preg_match('~\A(?:'.$trimmed.')\z~', '') === false) {
            throw InvalidTenantSchema::becauseBadPattern($pattern, 'le motif n\'est pas une expression régulière valide');
        }

        return new self($trimmed);
    }

    /**
     * La référence respecte-t-elle le motif ?
     *
     * Le motif est ancré sur la référence entière : `code-42` ne passe pas
     * un motif `code-`.
     */
    public function matches(string $reference): bool
    {
        return preg_match('~\A(?:'.$this->pattern.')\z~', $reference) === 1;
    }

    /**
     * Le motif ne décrit que des références du Core — la garantie de la
     * décision 6.
     */
    private static function assertOnlyDescribesCoreReferences(string $pattern): void
    {
        $length = strlen($pattern);

        for ($index = 0; $index < $length; $index++) {
            $char = $pattern[$index];

            if ($char === '\\') {
                $escaped = $index + 1 < $length ? $pattern[$index + 1] : '';

                if (! in_array($escaped, ['.', '-', '_', ':'], true)) {
                    throw InvalidTenantSchema::becauseBadPattern(
                        $pattern,
                        'le motif ne peut échapper que \\. \\- \\_ et \\: — un échappement large autoriserait un caractère hors de l\'alphabet',
                    );
                }

                $index++;

                continue;
            }

            if ($char === '[') {
                $end = self::classEnd($pattern, $index);
                self::assertClassIsWithinTheAlphabet(substr($pattern, $index + 1, $end - $index - 1), $pattern);
                $index = $end;

                continue;
            }

            if ($char === ']') {
                throw InvalidTenantSchema::becauseBadPattern($pattern, 'le motif porte un crochet fermant qui n\'ouvre rien');
            }

            if ($char === '{') {
                $index = self::quantifierEnd($pattern, $index);

                continue;
            }

            if ($char === '.') {
                throw InvalidTenantSchema::becauseBadPattern(
                    $pattern,
                    'le joker est interdit : il matcherait un caractère hors de l\'alphabet du Core',
                );
            }

            if ($char === '(') {
                $next = $index + 1 < $length ? $pattern[$index + 1] : '';

                if ($next === '?') {
                    throw InvalidTenantSchema::becauseBadPattern(
                        $pattern,
                        'les groupes étendus ((?i), (?P<…>), assertions) sont interdits : ils changent la lecture du motif',
                    );
                }
            }

            if ($char === '^' && $index !== 0) {
                throw InvalidTenantSchema::becauseBadPattern($pattern, 'l\'ancre ^ n\'est admise qu\'en tête de motif');
            }

            if ($char === '$' && $index !== $length - 1) {
                throw InvalidTenantSchema::becauseBadPattern($pattern, 'l\'ancre $ n\'est admise qu\'en fin de motif');
            }

            if (! str_contains(self::ALPHABET, $char) && ! in_array($char, ['(', ')', '|', '*', '+', '?', '{', '}', '^', '$'], true)) {
                throw InvalidTenantSchema::becauseBadPattern(
                    $pattern,
                    sprintf('le caractère "%s" est hors de l\'alphabet d\'une référence du Core', $char),
                );
            }
        }
    }

    /**
     * L'index de l'accolade fermante d'un quantificateur.
     *
     * Un quantificateur est lu **comme un bloc** : `{4}`, `{4,8}`, `{4,}`.
     * Sa virgule n'est pas un caractère de référence — c'est la borne de la
     * répétition — et le.Core ne la refuse donc pas (elle ne décrit rien
     * hors de l'alphabet).
     *
     * @throws InvalidTenantSchema le quantificateur est mal formé
     */
    private static function quantifierEnd(string $pattern, int $start): int
    {
        $length = strlen($pattern);
        $index = $start + 1;
        $digits = 0;

        while ($index < $length && ctype_digit($pattern[$index])) {
            $index++;
            $digits++;
        }

        if ($digits === 0) {
            throw InvalidTenantSchema::becauseBadPattern($pattern, 'un quantificateur doit porter un nombre');
        }

        if ($index < $length && $pattern[$index] === ',') {
            $index++;

            while ($index < $length && ctype_digit($pattern[$index])) {
                $index++;
            }
        }

        if ($index >= $length || $pattern[$index] !== '}') {
            throw InvalidTenantSchema::becauseBadPattern($pattern, 'un quantificateur est mal formé');
        }

        return $index;
    }

    /**
     * L'index du crochet fermant d'une classe de caractères.
     *
     * @throws InvalidTenantSchema la classe n'est pas fermée
     */
    private static function classEnd(string $pattern, int $start): int
    {
        $length = strlen($pattern);
        $index = $start + 1;

        // Un `]` juste après `[` est un littéral en PCRE.
        if ($index < $length && $pattern[$index] === ']') {
            $index++;
        }

        while ($index < $length) {
            if ($pattern[$index] === '\\') {
                $index += 2;

                continue;
            }

            if ($pattern[$index] === ']') {
                return $index;
            }

            $index++;
        }

        throw InvalidTenantSchema::becauseBadPattern($pattern, 'une classe de caractères n\'est pas fermée');
    }

    /**
     * @throws InvalidTenantSchema la classe décrit un caractère hors de
     *                             l'alphabet du Core
     */
    private static function assertClassIsWithinTheAlphabet(string $content, string $pattern): void
    {
        if ($content === '') {
            throw InvalidTenantSchema::becauseBadPattern($pattern, 'une classe de caractères est vide');
        }

        if (str_starts_with($content, '^')) {
            throw InvalidTenantSchema::becauseBadPattern(
                $pattern,
                'une classe négative est interdite : elle décrit des caractères hors de l\'alphabet du Core',
            );
        }

        $items = self::classItems($content);
        $count = count($items);

        for ($index = 0; $index < $count; $index++) {
            $item = $items[$index];

            if ($item['operator']) {
                throw InvalidTenantSchema::becauseBadPattern($pattern, 'un tiret de plage se tient seul dans une classe de caractères');
            }

            if ($index + 2 < $count && $items[$index + 1]['operator']) {
                if ($items[$index + 2]['operator']) {
                    throw InvalidTenantSchema::becauseBadPattern($pattern, 'une plage de classe de caractères est mal formée');
                }

                self::assertRangeIsWithinTheAlphabet($item['char'], $items[$index + 2]['char'], $pattern);
                $index += 2;

                continue;
            }

            self::assertCharIsWithinTheAlphabet($item['char'], $pattern);
        }
    }

    /**
     * Les littéraux d'une classe de caractères, dans l'ordre.
     *
     * @return list<array{char: string, operator: bool}>
     */
    private static function classItems(string $content): array
    {
        $items = [];
        $length = strlen($content);

        for ($index = 0; $index < $length; $index++) {
            $char = $content[$index];

            if ($char === '\\') {
                $escaped = $index + 1 < $length ? $content[$index + 1] : '';
                $items[] = ['char' => $escaped, 'operator' => false];
                $index++;

                continue;
            }

            // Un tiret non échappé est l'opérateur de plage — sauf aux deux
            // extrémités de la classe, où il est un littéral.
            $items[] = [
                'char' => $char,
                'operator' => $char === '-' && $index !== 0 && $index !== $length - 1,
            ];
        }

        return $items;
    }

    /**
     * @throws InvalidTenantSchema le caractère est hors de l'alphabet
     */
    private static function assertCharIsWithinTheAlphabet(string $char, string $pattern): void
    {
        if ($char === '' || ! str_contains(self::ALPHABET, $char)) {
            throw InvalidTenantSchema::becauseBadPattern(
                $pattern,
                sprintf('le caractère "%s" est hors de l\'alphabet d\'une référence du Core', $char),
            );
        }
    }

    /**
     * @throws InvalidTenantSchema la plage couvre un caractère hors de
     *                             l'alphabet
     */
    private static function assertRangeIsWithinTheAlphabet(string $from, string $to, string $pattern): void
    {
        $start = ord($from);
        $end = ord($to);

        if ($end < $start) {
            throw InvalidTenantSchema::becauseBadPattern($pattern, 'une plage de classe de caractères est à l\'envers');
        }

        for ($code = $start; $code <= $end; $code++) {
            $char = chr($code);

            if (! str_contains(self::ALPHABET, $char)) {
                throw InvalidTenantSchema::becauseBadPattern(
                    $pattern,
                    sprintf('la plage couvre "%s", qui est hors de l\'alphabet d\'une référence du Core', $char),
                );
            }
        }
    }
}
