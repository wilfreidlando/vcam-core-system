<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Core\Support\Integration\Redaction\SensitiveKeySet;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Garde-fou G-2 de l'annexe sécurité du 2026-10-29 : les deux listes qui
 * décident ce qui ne doit JAMAIS être journalisé doivent décrire la même
 * famille.
 *
 * `HEADER_NAMES` décide pour les en-têtes, `KEY_SUBSTRINGS` et `EXACT_KEYS`
 * pour les clés JSON et les champs de formulaire. Un secret qui a un nom
 * dans une liste mais pas dans l'autre passe en clair dans les journaux
 * selon la forme qu'il prend — c'est exactement ce qui est arrivé avec
 * `api-key` : l'en-tête `X-Api-Key` était expurgé, la clé JSON `api-key`
 * ne l'était pas (le substring `apikey`, sans tiret, ne matche pas).
 *
 * Ce test échoue dès qu'une liste grandit sans que l'autre suive : c'est
 * la famille de défaut qui revient.
 */
final class SensitiveKeySetGuardTest extends TestCase
{
    /**
     * The fields of a form that carry a credential. The name is the one the
     * form gives the field; the redactor must catch it.
     *
     * @var list<string>
     */
    private const CREDENTIAL_FIELDS = [
        'code',
        'otp',
        'totp',
        'login_pin',
        'login_code',
        'verification_code',
        'mfa_code',
        'sms_code',
        'signature',
        'mac',
        'hmac',
        'checksum',
    ];

    #[Test]
    public function every_sensitive_header_has_a_json_equivalent(): void
    {
        $keys = new SensitiveKeySet;

        foreach ($keys->headerNames() as $header) {
            self::assertTrue(
                $this->hasJsonEquivalent($header, $keys),
                sprintf(
                    'L\'en-tête sensible « %s » n\'a pas d\'équivalent dans les listes de clés JSON : '
                    .'un secret portant ce nom dans un corps JSON serait journalisé en clair. '
                    .'Ajoute son équivalent à KEY_SUBSTRINGS ou EXACT_KEYS.',
                    $header,
                ),
            );
        }
    }

    #[Test]
    public function every_credential_field_of_a_form_is_redacted(): void
    {
        $keys = new SensitiveKeySet;

        foreach (self::CREDENTIAL_FIELDS as $field) {
            self::assertTrue(
                $this->isRedacted($field, $keys),
                sprintf(
                    'Le champ « %s » porte un credential mais n\'est pas dans les listes d\'expurgation : '
                    .'il partirait en clair dans les journaux.',
                    $field,
                ),
            );
        }
    }

    #[Test]
    public function the_two_lists_are_not_empty(): void
    {
        // A list emptied by accident would make every test above pass for
        // the wrong reason.
        $keys = new SensitiveKeySet;

        self::assertGreaterThan(10, count($keys->headerNames()));
        self::assertGreaterThan(10, count($keys->keySubstrings()));
        self::assertGreaterThan(0, count($keys->exactKeys()));
    }

    /** Does a key of this name reach the JSON lists — as a substring, or exactly? */
    private function isRedacted(string $key, SensitiveKeySet $keys): bool
    {
        $lower = strtolower($key);

        foreach ($keys->keySubstrings() as $needle) {
            if (str_contains($lower, strtolower($needle))) {
                return true;
            }
        }

        return in_array($lower, array_map('strtolower', $keys->exactKeys()), true);
    }

    /**
     * The header `x-api-key` and the JSON key `api_key` are the same
     * secret: the dash, the underscore and the `x-` prefix are the same
     * word written differently. The comparison therefore ignores them.
     */
    private function hasJsonEquivalent(string $header, SensitiveKeySet $keys): bool
    {
        $normalized = $this->normalize($header);

        foreach ([...$keys->keySubstrings(), ...$keys->exactKeys()] as $candidate) {
            if ($this->normalize($candidate) === $normalized) {
                return true;
            }
        }

        return false;
    }

    /** `x-api-key`, `api_key` and `APIKEY` are the same word. */
    private function normalize(string $name): string
    {
        return str_replace(['x-', '-', '_'], '', strtolower($name));
    }
}
