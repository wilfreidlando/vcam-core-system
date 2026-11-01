<?php

declare(strict_types=1);

namespace Core\Admin\Presentation\Http\Controllers;

use Core\Admin\Presentation\Http\Controllers\Concerns\TranslatesOrganizationRefusals;
use Core\Identity\Contracts\LastOrganizationOwner;
use Core\Identity\Contracts\OperatorContext;
use Core\Identity\Contracts\OrganizationMemberNotFound;
use Core\Identity\Contracts\OrganizationNotVisible;
use Core\Identity\Contracts\OrganizationRefusal;
use Core\Identity\Contracts\RevokeOrganizationMember;
use Core\Identity\Contracts\RevokeOrganizationMemberCommand;
use Core\Support\Http\ProblemDetails;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * ADR-0069 §1 — revokes the membership of one account in one
 * organization.
 *
 * Revoking the **last** active owner is refused with `409 last-owner`:
 * an organization without an owner could no longer be administered.
 */
final class RevokeOrganizationMemberController
{
    use TranslatesOrganizationRefusals;

    public function __construct(
        private readonly RevokeOrganizationMember $revokeMember,
    ) {}

    /**
     * Revoke the membership of one account in an organization.
     *
     * Requires `members:write`. Revoking the **last** active owner is
     * refused with `409 last-owner` — an organization without an owner
     * could no longer be administered.
     *
     * @throws OrganizationNotVisible the organization is outside the caller's scope (404)
     * @throws OrganizationMemberNotFound the account is not a member of this organization (404)
     * @throws LastOrganizationOwner the account is the last active owner of this organization (409)
     */
    public function __invoke(OperatorContext $operator, string $organizationId, string $operatorId): JsonResponse|Response
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

        try {
            $this->revokeMember->revoke(
                new RevokeOrganizationMemberCommand(
                    organizationId: $organizationId,
                    operatorId: $operatorId,
                    actorOperatorId: $operator->operatorId,
                ),
                $operator->scope,
                actor: 'operator:'.$operator->operatorId,
                correlationId: null,
            );
        } catch (OrganizationRefusal $refusal) {
            return $this->refusalResponse($refusal);
        }

        return new JsonResponse(null, 204);
    }
}
