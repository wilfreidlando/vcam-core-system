<?php

declare(strict_types=1);

namespace Core\Admin\Presentation\Http\Controllers;

use Core\Admin\Presentation\Http\Controllers\Concerns\TranslatesOrganizationRefusals;
use Core\Identity\Contracts\ChangeOrganizationMemberRole;
use Core\Identity\Contracts\ChangeOrganizationMemberRoleCommand;
use Core\Identity\Contracts\InvalidOrganizationRole;
use Core\Identity\Contracts\LastOrganizationOwner;
use Core\Identity\Contracts\OperatorContext;
use Core\Identity\Contracts\OrganizationMemberNotFound;
use Core\Identity\Contracts\OrganizationNotVisible;
use Core\Identity\Contracts\OrganizationPrivilegeEscalation;
use Core\Identity\Contracts\OrganizationRefusal;
use Core\Identity\Contracts\PlatformRoleAssignmentForbidden;
use Core\Support\Http\ProblemDetails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ADR-0069 — changes the role one account holds in one organization.
 *
 * Demoting the **last** active owner is refused exactly like revoking
 * it (`409 last-owner`), and nobody can grant a platform role or a
 * role above their own through this path.
 */
final class ChangeOrganizationMemberRoleController
{
    use TranslatesOrganizationRefusals;

    public function __construct(
        private readonly ChangeOrganizationMemberRole $changeRole,
    ) {}

    /**
     * Change the role one account holds in an organization.
     *
     * Requires `members:write`. Demoting the **last** active owner is
     * refused exactly like revoking it (`409 last-owner`), and nobody can
     * grant a platform role or a role above their own.
     *
     * @throws OrganizationNotVisible the organization is outside the caller's scope (404)
     * @throws OrganizationMemberNotFound the account is not a member of this organization (404)
     * @throws LastOrganizationOwner the account is the last active owner of this organization (409)
     * @throws OrganizationPrivilegeEscalation the role would exceed the caller's own (403)
     * @throws PlatformRoleAssignmentForbidden a platform role cannot be granted through an organization (403)
     * @throws InvalidOrganizationRole the role is not an organization role (422)
     */
    public function __invoke(Request $request, OperatorContext $operator, string $organizationId, string $operatorId): JsonResponse|Response
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

        /** @var array{role: string} $data */
        // @phpstan-ignore-next-line — Laravel's Request::validate() return type is dynamic-array in stubs.
        $data = $request->validate([
            'role' => ['required', 'string', 'max:64'],
        ]);

        try {
            $this->changeRole->changeRole(
                new ChangeOrganizationMemberRoleCommand(
                    organizationId: $organizationId,
                    operatorId: $operatorId,
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

        return new JsonResponse(null, 204);
    }
}
