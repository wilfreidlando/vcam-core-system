<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use Core\Identity\Contracts\AcceptOperatorInvitation;
use Core\Identity\Contracts\AcceptOperatorInvitationCommand;
use Core\Identity\Contracts\AcceptOrganizationInvitation;
use Core\Identity\Contracts\AcceptOrganizationInvitationCommand;
use Core\Identity\Contracts\AlreadyOrganizationMember;
use Core\Identity\Contracts\InvitationAccountPasswordInvalid;
use Core\Identity\Contracts\InvitationNotUsable;
use Core\Identity\Contracts\InvitationPasswordTooWeak;
use Core\Identity\Contracts\OperatorAlreadyExists;
use Core\Identity\Contracts\OperatorEmailAlreadyTaken;
use Core\Identity\Contracts\OperatorInvitationNotFound;
use Core\Identity\Contracts\OperatorInvitationNotUsable;
use Core\Identity\Contracts\OrganizationInvitationNotFound;
use Core\Identity\Contracts\OrganizationRefusal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

/**
 * The invitee's door (ADR-0069 §4): the page where an activation token becomes an account.
 *
 * An invitation reaches a person two ways — to be a console operator, or to join an
 * organization — and the person does not know which: they paste the token they were given and
 * the page finds out. The password is **chosen here by the invitee**; the system never picks
 * one nor transmits one (ADR-0081 S-07). Once accepted, the invitee is handed to the same MFA
 * steps as a login, so an account is never usable before its second factor is enrolled.
 *
 * The token is typed or pasted, never carried in the address: a URL ends up in logs, histories and
 * referrers, a form field does not.
 */
final class InvitationController
{
    public function __construct(
        private readonly AcceptOperatorInvitation $operatorInvitation,
        private readonly AcceptOrganizationInvitation $organizationInvitation,
    ) {}

    public function show(): Response
    {
        return response()->view('admin.auth.invitation', ['title' => 'Activer mon compte']);
    }

    public function accept(Request $request): RedirectResponse
    {
        /** @var array{token: string, name?: string|null, password: string} $data */
        $data = Validator::make($request->all(), [
            'token' => ['required', 'string', 'min:32', 'max:200'],
            'name' => ['nullable', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255', 'confirmed'],
        ], [
            'token.required' => 'Collez le jeton d\'activation qui vous a été remis.',
            'token.min' => 'Ce jeton est trop court : copiez-le en entier.',
            'password.confirmed' => 'Les deux mots de passe ne sont pas identiques.',
        ])->validate();

        $name = $data['name'] ?? '';

        try {
            try {
                $accepted = $this->operatorInvitation->accept(new AcceptOperatorInvitationCommand($data['token'], $name, $data['password']));
            } catch (OperatorInvitationNotFound) {
                // Not an operator invitation: it may be one to join an organization.
                $accepted = $this->organizationInvitation->accept(new AcceptOrganizationInvitationCommand($data['token'], $name, $data['password']));
            }
        } catch (OrganizationRefusal|OperatorEmailAlreadyTaken $refusal) {
            return back()->withErrors(['token' => $this->say($refusal)]);
        }

        // From here it is a login in progress: the second factor comes next.
        Session::regenerate();
        Session::put('admin.challenge_token', $accepted->challengeToken);
        Session::put('admin.challenge_purpose', $accepted->challengePurpose);
        Session::put('admin.email', $accepted->email);

        return match ($accepted->challengePurpose) {
            'mfa_enrollment' => redirect()->route('admin.mfa.enroll.show')->with('status', 'Compte activé : configurez maintenant votre authentificateur.'),
            default => redirect()->route('admin.mfa.verify.show')->with('status', 'Invitation acceptée : saisissez le code de votre authentificateur.'),
        };
    }

    /** The refusal, in words the invitee can act on. */
    private function say(OrganizationRefusal|OperatorEmailAlreadyTaken $refusal): string
    {
        return match (true) {
            $refusal instanceof OrganizationInvitationNotFound, $refusal instanceof OperatorInvitationNotFound => 'Aucune invitation ne correspond à ce jeton. Vérifiez que vous l\'avez copié en entier.',
            $refusal instanceof InvitationNotUsable, $refusal instanceof OperatorInvitationNotUsable => match (true) {
                str_contains($refusal->getMessage(), 'expired') => 'Cette invitation a expiré (72 heures). Demandez-en une nouvelle.',
                str_contains($refusal->getMessage(), 'revoked') => 'Cette invitation a été révoquée. Demandez-en une nouvelle.',
                default => 'Cette invitation a déjà été utilisée : connectez-vous avec votre compte.',
            },
            $refusal instanceof InvitationPasswordTooWeak => sprintf('Choisissez un mot de passe d\'au moins %d caractères.', $refusal->minimumLength),
            $refusal instanceof InvitationAccountPasswordInvalid => 'Vous avez déjà un compte : saisissez son mot de passe actuel, qui n\'est pas remplacé.',
            $refusal instanceof AlreadyOrganizationMember => 'Vous êtes déjà membre de cette organisation.',
            $refusal instanceof OperatorAlreadyExists, $refusal instanceof OperatorEmailAlreadyTaken => 'Un compte existe déjà avec cette adresse e-mail : connectez-vous.',
            default => 'Cette invitation ne peut pas être acceptée.',
        };
    }
}
