<?php

declare(strict_types=1);

namespace Core\Admin\Presentation\Http\Controllers;

use Core\Admin\Presentation\Http\Controllers\Concerns\TranslatesOrganizationRefusals;
use Core\Identity\Contracts\AcceptOrganizationInvitation;
use Core\Identity\Contracts\AcceptOrganizationInvitationCommand;
use Core\Identity\Contracts\AlreadyOrganizationMember;
use Core\Identity\Contracts\InvitationAccountPasswordInvalid;
use Core\Identity\Contracts\InvitationNotUsable;
use Core\Identity\Contracts\InvitationPasswordTooWeak;
use Core\Identity\Contracts\OperatorAlreadyExists;
use Core\Identity\Contracts\OrganizationInvitationNotFound;
use Core\Identity\Contracts\OrganizationRefusal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ADR-0069 §4 — consumes an activation token and creates the console
 * account it was issued for.
 *
 * This endpoint is **public**: the invitee is not signed in yet — that
 * is the whole point of the invitation. It carries the same tight
 * throttle as the login endpoints, because the token is the only thing
 * standing between an attacker and a console account.
 *
 * The MFA step that follows is the existing one: the challenge returned
 * here is the one `POST /v1/operators/login` would have returned for the
 * account (ADR-0044 / ADR-0046).
 */
final class AcceptOrganizationInvitationController
{
    use TranslatesOrganizationRefusals;

    public function __construct(
        private readonly AcceptOrganizationInvitation $acceptInvitation,
    ) {}

    /**
     * Accept an organization invitation.
     *
     * Public endpoint — the invitee is not signed in yet: the invitation
     * is the only thing that grants access. Consumes the activation token
     * (32 random bytes, hashed, 72 h, one-shot) and returns the MFA
     * challenge of the account it was issued for.
     *
     * @throws OrganizationInvitationNotFound the token matches no invitation (404)
     * @throws InvitationNotUsable the invitation was revoked, already accepted or expired (409)
     * @throws AlreadyOrganizationMember the email is already a member of the organization (409)
     * @throws OperatorAlreadyExists the console account of this email is revoked (409)
     * @throws InvitationPasswordTooWeak the chosen password is too weak (422)
     * @throws InvitationAccountPasswordInvalid the password of the existing account is wrong (422)
     */
    public function __invoke(Request $request): JsonResponse|Response
    {
        /** @var array{token: string, name?: string|null, password: string} $data */
        // @phpstan-ignore-next-line — Laravel's Request::validate() return type is dynamic-array in stubs.
        $data = $request->validate([
            'token' => ['required', 'string', 'min:32', 'max:200'],
            // The display name of a new account; ignored when the invited
            // email already carries an account.
            'name' => ['nullable', 'string', 'max:255'],
            // ADR-0069 §4 — for a new account, the password the invitee
            // chooses (the system never chooses one, never transmits one);
            // for an existing account, its current password — verified,
            // never replaced. The length rule of a new password is the use
            // case's, so an existing account's password is not judged here.
            'password' => ['required', 'string', 'max:255'],
        ]);

        try {
            $accepted = $this->acceptInvitation->accept(new AcceptOrganizationInvitationCommand(
                token: $data['token'],
                name: $data['name'] ?? '',
                password: $data['password'],
            ));
        } catch (OrganizationRefusal $refusal) {
            return $this->refusalResponse($refusal);
        }

        return new JsonResponse([
            'operator_id' => $accepted->operatorId,
            'organization_id' => $accepted->organizationId,
            'role' => $accepted->role,
            'email' => $accepted->email,
            'account_created' => $accepted->accountCreated,
            // The same pair `POST /v1/operators/login` returns:
            // `mfa_enrollment` → `POST /v1/operators/mfa/enroll` then
            // `/mfa/confirm`; `mfa` → `POST /v1/operators/mfa/verify`.
            'purpose' => $accepted->challengePurpose,
            'challenge_token' => $accepted->challengeToken,
        ], 201);
    }
}
