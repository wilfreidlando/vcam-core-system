<?php

declare(strict_types=1);

namespace Core\Identity\Presentation\Http\Middleware;

use Core\Identity\Application\UseCase\VerifyOperatorStepUp;
use Illuminate\Support\Facades\Session;

/**
 * Écart #5 (annexe sécurité 2026-10-29, G-4) — la mémoire de l'élévation, côté session.
 *
 * Une action sensible de la console exige que l'opérateur ait saisi un code TOTP
 * valide dans les `WINDOW_SECONDS` dernières secondes. La preuve est un horodatage
 * dans la session serveur ; le code, lui, est vérifié par `VerifyOperatorStepUp`
 * (même vérificateur et même anti-rejeu que le login).
 *
 * Le login MFA est lui-même une preuve TOTP fraîche : `AuthController::completeLogin()`
 * appelle `markElevated()` pour que l'opérateur n'ait pas à se réélever à la seconde
 * qui suit sa connexion.
 */
final readonly class StepUpGate
{
    /** Clé de session : l'instant (timestamp Unix) de la dernière preuve TOTP. */
    public const SESSION_KEY = 'operator_step_up_at';

    /** Clé de session : la page où revenir (chemin local) une fois l'élévation obtenue. */
    public const RETURN_KEY = 'operator_step_up_return_to';

    /** Durée de validité d'une élévation : 5 minutes. */
    public const WINDOW_SECONDS = 300;

    public function __construct(
        private VerifyOperatorStepUp $verify,
    ) {}

    /**
     * Faut-il une élévation pour cet opérateur ? Non quand le MFA est coupé
     * (`IDENTITY_DISABLE_MFA`) ou que l'opérateur n'en a pas : il n'y a alors
     * aucun code à ressaisir, et le login ne l'en a pas demandé non plus.
     */
    public function isRequiredFor(string $operatorId): bool
    {
        if ((bool) config('identity.disable_mfa', false)) {
            return false;
        }

        return $this->verify->isRequiredFor($operatorId);
    }

    /** La session porte-t-elle une preuve TOTP de moins de cinq minutes ? */
    public function isElevated(): bool
    {
        $at = Session::get(self::SESSION_KEY);
        if (! is_int($at)) {
            return false;
        }

        $age = now()->getTimestamp() - $at;

        return $age >= 0 && $age < self::WINDOW_SECONDS;
    }

    /** Consigne une preuve TOTP à l'instant présent (appelé aussi par le login MFA). */
    public function markElevated(): void
    {
        Session::put(self::SESSION_KEY, now()->getTimestamp());
    }

    /**
     * Vérifie le code (anti-rejeu compris), consigne l'élévation et fait tourner
     * l'identifiant de session (ADR-0081 : un changement de privilège change de session).
     *
     * @throws \Core\Identity\Domain\Exception\InvalidMfaCode
     * @throws \Core\Identity\Domain\Exception\MfaNotEnrolled
     * @throws \Core\Identity\Domain\Exception\OperatorRevoked
     * @throws \Core\Identity\Domain\Exception\OperatorNotFound
     */
    public function elevate(string $operatorId, string $code): void
    {
        ($this->verify)($operatorId, $code);

        $this->markElevated();
        Session::regenerate();
    }

    /** Retient la page où revenir après l'élévation. */
    public function rememberDestination(string $path): void
    {
        Session::put(self::RETURN_KEY, $path);
    }

    /** Lit puis oublie la page où revenir ; `$default` si rien n'a été retenu. */
    public function pullDestination(string $default): string
    {
        $path = Session::pull(self::RETURN_KEY);

        return is_string($path) && $path !== '' ? $path : $default;
    }
}
