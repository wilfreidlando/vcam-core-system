<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Garde-fou G-3 de l'annexe sécurité (écart #4, ADR-0081 S-07) : aucun secret en clair n'est
 * écrit dans le STORE DE SESSION par un contrôleur de la console.
 *
 * Un `session()->flash(...)` ou un `->with(...)` de redirection écrit la valeur dans le store
 * (Redis/Valkey en production), où elle survit à la réponse qui la porte. Le secret est donc
 * rendu DANS la réponse du POST qui le crée (modèle : `OrganizationMembersController::store`),
 * jamais flashé pour une redirection.
 *
 * Le test lit le code des contrôleurs (commentaires retirés) : il ne dépend d'aucune base ni
 * d'aucun navigateur. Le comportement vivant (le POST rend le secret, le store ne le contient
 * pas) est prouvé par `tests/Feature/Admin/ConsoleSecretsAreNotSessionedTest.php`.
 */
final class ConsoleSecretFlashTest extends TestCase
{
    private const CONTROLLERS = __DIR__.'/../../app/Http/Controllers/Admin';

    /**
     * Les écritures de FLASH (survivent à la réponse) et les relectures de clé littérale. Les
     * `Session::put` de l'authentification (jeton de défi MFA, session de l'opérateur) sont
     * le mécanisme même de la connexion, pas un secret affiché : l'écart #15 les traite.
     */
    private const SESSION_CALL = '/(?:session\(\)->(?:flash|now|reflash)\s*\(|Session::(?:flash|now)\s*\(|->with\s*\(|->withInput\s*\(|\bsession\s*\((?=\s*[\'"]))/';

    /** Ce qui désigne un secret : le nom de la clé de session ou la valeur qu'on y met. */
    private const SECRET_MARKER = '/plaintext|secret|token|api[-_]?key|password|mot_?de_?passe/i';

    /**
     * Dette connue et nommée : le contrôleur et la raison. Ce test refuse qu'elle grandisse ;
     * quand le contrôleur est corrigé, la ligne se supprime (le test vérifie qu'elle est encore
     * utile et le dit sinon).
     *
     * @var array<string, string>
     */
    private const KNOWN_DEBT = [
        'ProjectsController.php' => 'la première clé du projet est flashée (admin.projects.created_api_key) — hors du périmètre du correctif #4, à rendre dans la réponse du POST',
    ];

    #[Test]
    public function no_console_controller_writes_a_secret_into_the_session_store(): void
    {
        $offenders = [];

        foreach ($this->controllers() as $name => $code) {
            foreach ($this->sessionCalls($code) as $call) {
                if ($this->bearsSecret($call) && ! isset(self::KNOWN_DEBT[$name])) {
                    $offenders[] = $name.' : '.preg_replace('/\s+/', ' ', $call);
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            "Ces contrôleurs écrivent (ou relisent) un secret dans le store de session (ADR-0081 S-07).\n"
            ."Rendez le secret dans la réponse du POST, comme OrganizationMembersController::store :\n  ".implode("\n  ", $offenders),
        );
    }

    #[Test]
    public function the_four_secret_creating_controllers_of_the_audit_are_clean_and_do_not_read_the_session_for_a_secret(): void
    {
        foreach (['ApiKeysController.php', 'WebhookEndpointsController.php', 'OperatorsController.php', 'OrganizationMembersController.php'] as $name) {
            $code = $this->controllers()[$name] ?? null;

            self::assertNotNull($code, "{$name} est introuvable.");
            self::assertStringNotContainsString('session()->flash', $code, "{$name} ne flashe plus rien.");
            self::assertDoesNotMatchRegularExpression("/session\\(\\s*'admin\\.[a-z.-]*(created|rotated)/", $code, "{$name} ne relit plus de secret dans la session.");
        }
    }

    #[Test]
    public function the_known_debt_is_still_real(): void
    {
        foreach (array_keys(self::KNOWN_DEBT) as $name) {
            $hits = array_filter(
                $this->sessionCalls($this->controllers()[$name] ?? ''),
                fn (string $call): bool => $this->bearsSecret($call),
            );

            self::assertNotSame([], $hits, "{$name} n'écrit plus de secret en session : supprimez-le de KNOWN_DEBT.");
        }
    }

    #[Test]
    public function the_scan_actually_sees_the_calls_it_is_looking_for(): void
    {
        // Un scan qui ne trouve rien passerait tous les tests ci-dessus.
        $total = 0;
        foreach ($this->controllers() as $code) {
            $total += count($this->sessionCalls($code));
        }

        self::assertGreaterThan(20, $total);
        self::assertGreaterThan(20, count($this->controllers()));
    }

    /**
     * @return array<string, string> nom du fichier => code sans commentaires
     */
    private function controllers(): array
    {
        $files = glob(self::CONTROLLERS.'/*.php') ?: [];
        $out = [];

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            $code = '';

            foreach (token_get_all($source) as $token) {
                if (is_array($token)) {
                    if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                        continue;
                    }
                    $code .= $token[1];
                } else {
                    $code .= $token;
                }
            }

            $out[basename($file)] = $code;
        }

        return $out;
    }

    /**
     * La clé de session (premier argument) ou la valeur qu'on y met désigne-t-elle un secret ?
     * Les phrases entre guillemets des arguments suivants (« Ancien secret révoqué. ») sont des
     * messages, pas des valeurs : seuls les noms de variables y comptent.
     */
    private function bearsSecret(string $call): bool
    {
        $comma = strpos($call, ',');
        $key = $comma === false ? $call : substr($call, 0, $comma);
        $rest = $comma === false ? '' : substr($call, $comma);
        $rest = (string) preg_replace('/\'(?:\\\\.|[^\'\\\\])*\'|"(?:\\\\.|[^"\\\\])*"/s', "''", $rest);

        return preg_match(self::SECRET_MARKER, $key.$rest) === 1;
    }

    /**
     * Chaque appel de session / flash, de sa parenthèse ouvrante à sa parenthèse fermante.
     *
     * @return list<string>
     */
    private function sessionCalls(string $code): array
    {
        $calls = [];

        if (preg_match_all(self::SESSION_CALL, $code, $matches, PREG_OFFSET_CAPTURE) === false) {
            return [];
        }

        foreach ($matches[0] as [$text, $offset]) {
            $start = $offset + strlen($text);
            $depth = 1;
            $end = $start;

            for ($length = strlen($code); $end < $length && $depth > 0; $end++) {
                $depth += match ($code[$end]) {
                    '(' => 1,
                    ')' => -1,
                    default => 0,
                };
            }

            $calls[] = $text.substr($code, $start, $end - $start);
        }

        return $calls;
    }
}
