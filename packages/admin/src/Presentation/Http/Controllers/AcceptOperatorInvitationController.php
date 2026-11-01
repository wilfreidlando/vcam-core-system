<?php

declare(strict_types=1);

namespace Core\Admin\Presentation\Http\Controllers;

use Core\Admin\Presentation\Http\Controllers\Concerns\TranslatesOrganizationRefusals;
use Core\Identity\Contracts\AcceptOperatorInvitation;
use Core\Identity\Contracts\AcceptOperatorInvitationCommand;
use Core\Identity\Contracts\OrganizationRefusal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ADR-0081 S-07 — activates a console account invitation.
 *
 * Public endpoint — the invitee is not signed in yet: the invitation is
 * the only thing that grants access. Consumes the activation token
 * (32 random bytes, hashed, 72 h, one-shot), creates the account with
 * the password the invitee **chooses**, and returns the MFA challenge
 * of that account — exactly the pair `POST /v1/operators/login` returns
 * (`mfa_enrollment` → `/v1/operators/mfa/enroll` then `/mfa/confirm`).
 *
 * @throws \Core\Identity\Contracts\OperatorInvitationNotFound the token matches no invitation (404)
 * @throws \Core\Identity\Contracts\OperatorInvitationNotUsable the invitation was revoked, already accepted or expired (409)
 * @throws \Core\Identity\Contracts\InvitationPasswordTooWeak the chosen password is too weak (422)
 */
final class AcceptOperatorInvitationController
{
    use TranslatesOrganizationRefusals;

    public function __construct(
        private readonly AcceptOperatorInvitation $acceptInvitation,
    ) {}

    /**
     * Activate a console account invitation.
     *
     * Public endpoint — the invitation token is the only thing that
     * grants access: the invitee is not signed in yet. Consumes the
     * token (one-shot, 72 h) and creates the account with the password
     * the invitee **chooses**.
     *
     * @throws \Core\Identity\Contracts\OperatorInvitationNotFound the token matches no invitation (404)
     * @throws \Core\Identity\Contracts\OperatorInvitationNotUsable the invitation was revoked, already accepted or expired (409)
     * @throws \Core\Identity\Contracts\InvitationPasswordTooWeak the chosen password is too weak (422)
     */
    public function __invoke(Request $request): JsonResponse|Response
    {
        /** @var array{token: string, name?: string|null, password: string} $data */
        // @phpstan-ignore-next-line — Laravel's Request::validate() return type is dynamic-array in stubs.
        $data = $request->validate([
            'token' => ['required', 'string', 'min:32', 'max:200'],
            // The display name of the account.
            'name' => ['nullable', 'string', 'max:255'],
            // ADR-0069 §4 — the password the invitee chooses (the system
            // never chooses one, never transmits one). The length rule
            // is the use case's, so the refusal is a 422 of its own.
            'password' => ['required', 'string', 'max:255'],
        ]);

        try {
            $accepted = $this->acceptInvitation->accept(new AcceptOperatorInvitationCommand(
                token: $data['token'],
                name: $data['name'] ?? '',
                password: $data['password'],
            ));
        } catch (OrganizationRefusal $refusal) {
            return $this->refusalResponse($refusal);
        }

        return new JsonResponse([
            'operator_id' => $accepted->operatorId,
            'email' => $accepted->email,
            'role' => $accepted->role,
            'account_created' => $accepted->accountCreated,
            // The same pair `POST /v1/operators/login` returns:
            // `mfa_enrollment` → `POST /v1/operators/mfa/enroll` then
            // `/mfa/confirm`.
            'purpose' => $accepted->challengePurpose,
            'challenge_token' => $accepted->challengeToken,
        ], 201);
    }
}
