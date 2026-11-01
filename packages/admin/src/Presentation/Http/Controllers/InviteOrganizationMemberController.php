<?php

declare(strict_types=1);

namespace Core\Admin\Presentation\Http\Controllers;

use Core\Admin\Presentation\Http\Controllers\Concerns\TranslatesOrganizationRefusals;
use Core\Identity\Contracts\AlreadyOrganizationMember;
use Core\Identity\Contracts\InviteOrganizationMember;
use Core\Identity\Contracts\InviteOrganizationMemberCommand;
use Core\Identity\Contracts\OperatorContext;
use Core\Identity\Contracts\OrganizationInvitationAlreadyExists;
use Core\Identity\Contracts\OrganizationNotVisible;
use Core\Identity\Contracts\OrganizationRefusal;
use Core\Support\Http\ProblemDetails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ADR-0069 §4 — invites a member into an organization.
 *
 * The clear activation token is returned **once**, here, and never
 * again (ADR-0081 S-07): it is not stored, not logged and not
 * retrievable afterwards.
 */
final class InviteOrganizationMemberController
{
    use TranslatesOrganizationRefusals;

    public function __construct(
        private readonly InviteOrganizationMember $inviteMember,
    ) {}

    /**
     * Invite an account into an organization.
     *
     * Requires `members:write`. The activation token is returned **once**,
     * here, and is never retrievable again (ADR-0081 S-07).
     *
     * @throws OrganizationNotVisible the organization is outside the caller's scope (404)
     * @throws AlreadyOrganizationMember the email is already a member of this organization (409)
     * @throws OrganizationInvitationAlreadyExists an invitation is already pending for this email (409)
     */
    public function __invoke(Request $request, OperatorContext $operator, string $organizationId): JsonResponse|Response
    {
        // ADR-0069 §2 — outside the scope: 404, never 403; visible but
        // without members:write there: 403.
        $refusal = $this->organizationRefusal($operator, $organizationId, 'members:write');
        if ($refusal === 'not-found') {
            return ProblemDetails::make('organization-not-found', 'Organization not found', 404, 'No organization matches this identifier for this account.');
        }
        if ($refusal === 'forbidden') {
            return ProblemDetails::forbidden('The members:write permission is required in this organization.');
        }

        /** @var array{email: string, role: string} $data */
        // @phpstan-ignore-next-line — Laravel's Request::validate() return type is dynamic-array in stubs.
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'role' => ['required', 'string', 'max:64'],
        ]);

        try {
            $invitation = $this->inviteMember->invite(
                new InviteOrganizationMemberCommand(
                    organizationId: $organizationId,
                    email: $data['email'],
                    role: $data['role'],
                    actorOperatorId: $operator->operatorId,
                ),
                $operator->scope,
                actor: 'operator:'.$operator->operatorId,
                correlationId: null,
            );
        } catch (OrganizationRefusal $refusal) {
            return $this->refusalResponse($refusal);
        }

        return new JsonResponse([
            'id' => $invitation->id,
            'organization_id' => $invitation->organizationId,
            'email' => $invitation->email,
            'role' => $invitation->role,
            // The one and only moment the clear token exists.
            'token' => $invitation->token,
            'expires_at' => $invitation->expiresAt->format(DATE_ATOM),
        ], 201);
    }
}
