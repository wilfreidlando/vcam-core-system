<?php

declare(strict_types=1);

namespace Tests\Architecture\SecretLeak;

/**
 * The name-matching core of the G14 fitness function (ADR-0081, S-07).
 *
 * Two responsibilities, kept out of the test file so the test file stays
 * a readable inventory of decisions rather than a pile of string munging:
 *
 *  1. deciding whether a NAME looks like a secret;
 *  2. walking an arbitrary decoded JSON payload and reporting every key
 *     whose name looks like a secret, at any depth.
 *
 * Matching is done on WORDS, never on raw substrings. `api_key_prefix`
 * contains the word sequence `api key` and is therefore *reported* — the
 * verdict (a prefix is not a secret) is then a reviewed decision, not
 * something this class guesses. `masked_public_key` contains no secret
 * term at all and is never reported.
 *
 * A name alone never proves a value is a secret: that is exactly why the
 * guard pairs this detector with an explicit, per-field inventory.
 */
final class SecretLeakDetector
{
    /**
     * The word sequences that make a name suspicious.
     *
     * Each entry is a sequence of words the normalized name must contain,
     * contiguously. `['api', 'key']` matches `api_key`, `apiKey` and
     * `api_key_prefix`, but not `public_key`.
     *
     * `client_secret` needs no entry of its own: the single word `secret`
     * already covers it, and every other multi-word secret term here
     * contains a word that is already listed.
     *
     * @var list<list<string>>
     */
    public const SECRET_TERMS = [
        ['secret'],
        ['token'],
        ['password'],
        ['passwd'],
        ['passphrase'],
        ['credential'],
        ['credentials'],
        ['otpauth'],
        ['bearer'],
        ['api', 'key'],
        ['apikey'],
        ['private', 'key'],
        ['access', 'key'],
        ['signing', 'key'],
    ];

    /**
     * Split a property or JSON key into its words.
     *
     * `camelCase` and `PascalCase` are split on their humps, so
     * `plaintextToken` and `plaintext_token` both yield
     * `['plaintext', 'token']`.
     *
     * @return list<string>
     */
    public static function words(string $name): array
    {
        $spaced = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $name) ?? $name;
        $spaced = preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1_$2', $spaced) ?? $spaced;

        $lowered = strtolower($spaced);
        $cleaned = preg_replace('/[^a-z0-9]+/', '_', $lowered) ?? $lowered;

        return array_values(array_filter(explode('_', $cleaned), static fn (string $w): bool => $w !== ''));
    }

    /**
     * The secret terms a name matches — empty when the name is innocent.
     *
     * @return list<string>
     */
    public static function matchedTerms(string $name): array
    {
        $words = self::words($name);

        $matched = [];
        foreach (self::SECRET_TERMS as $term) {
            if (self::containsRun($words, $term)) {
                $matched[] = implode('_', $term);
            }
        }

        return $matched;
    }

    /**
     * Whether a name is worth a human verdict.
     */
    public static function looksSecretish(string $name): bool
    {
        return self::matchedTerms($name) !== [];
    }

    /**
     * Every secret-looking key of a decoded JSON payload, at any depth.
     *
     * @return list<array{path: string, key: string, terms: list<string>}>
     */
    public static function secretishKeysIn(mixed $payload, string $path = ''): array
    {
        if (! is_array($payload)) {
            return [];
        }

        $found = [];

        foreach ($payload as $key => $value) {
            $childPath = $path === '' ? (string) $key : $path.'.'.$key;

            if (is_string($key)) {
                $terms = self::matchedTerms($key);
                if ($terms !== []) {
                    $found[] = ['path' => $childPath, 'key' => $key, 'terms' => $terms];
                }
            }

            foreach (self::secretishKeysIn($value, $childPath) as $nested) {
                $found[] = $nested;
            }
        }

        return $found;
    }

    /**
     * @param list<string> $words
     * @param list<string> $term
     */
    private static function containsRun(array $words, array $term): bool
    {
        $termLength = count($term);
        if ($termLength === 0 || count($words) < $termLength) {
            return false;
        }

        for ($offset = 0; $offset <= count($words) - $termLength; $offset++) {
            if (array_slice($words, $offset, $termLength) === $term) {
                return true;
            }
        }

        return false;
    }
}
