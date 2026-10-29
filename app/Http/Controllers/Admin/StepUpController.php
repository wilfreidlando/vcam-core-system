<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use Core\Identity\Contracts\OperatorContext;
use Core\Identity\Domain\Exception\InvalidMfaCode;
use Core\Identity\Domain\Exception\MfaNotEnrolled;
use Core\Identity\Domain\Exception\OperatorNotFound;
use Core\Identity\Domain\Exception\OperatorRevoked;
use Core\Identity\Presentation\Http\Middleware\StepUpGate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Écart #5 (annexe sécurité 2026-10-29) — l'écran de ressaisie du code TOTP avant
 * une action sensible (`operator.step_up`). Le code est vérifié par le même
 * vérificateur et le même anti-rejeu que le login : un code déjà consommé est refusé.
 */
final class StepUpController
{
    public function __construct(
        private readonly StepUpGate $gate,
        private readonly OperatorContext $operator,
    ) {}

    public function show(): Response|RedirectResponse
    {
        // Rien à ressaisir (MFA coupée) : on renvoie l'opérateur où il allait.
        if (! $this->gate->isRequiredFor($this->operator->operatorId)) {
            return redirect($this->gate->pullDestination(route('admin.dashboard', [], false)));
        }

        return response()->view('admin.auth.step-up', ['title' => 'Confirmer votre identité']);
    }

    public function verify(Request $request): RedirectResponse
    {
        /** @var array{code: string} $data */
        // @phpstan-ignore-next-line — Laravel's Request::validate() return type is dynamic-array in stubs.
        $data = $request->validate([
            'code' => ['required', 'string', 'max:12'],
        ], [
            'code.required' => 'Saisissez le code à 6 chiffres de votre application d\'authentification.',
        ]);

        try {
            $this->gate->elevate($this->operator->operatorId, $data['code']);
        } catch (InvalidMfaCode|MfaNotEnrolled) {
            return back()->withErrors(['code' => 'Code invalide, expiré ou déjà utilisé. Attendez le prochain code de votre application.']);
        } catch (OperatorRevoked|OperatorNotFound) {
            return redirect()->route('admin.login.show')
                ->withErrors(['email' => 'Votre accès opérateur n\'est plus valide.']);
        }

        return redirect($this->gate->pullDestination(route('admin.dashboard', [], false)))
            ->with('status', 'Identité confirmée pendant 5 minutes. Vous pouvez recommencer l\'action.');
    }
}
