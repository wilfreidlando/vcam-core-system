<?php

declare(strict_types=1);

namespace Core\Admin\Presentation\Http\Controllers;

use Core\Admin\Presentation\Http\Controllers\Concerns\TranslatesOrganizationRefusals;
use Core\Identity\Contracts\ListOrganizationMembers;
use Core\Identity\Contracts\ListOrganizationMembersCommand;
use Core\Identity\Contracts\OperatorContext;
use Core\Identity\Contracts\OrganizationMemberView;
use Core\Identity\Contracts\OrganizationNotVisible;
use Core\Identity\Contracts\OrganizationRefusal;
use Core\Support\Http\ProblemDetails;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * ADR-0069 §1 — the members of an organization.
 *
 * The scope is required (ADR-0069 §2): an organization outside the
 * caller's scope answers **404**, never 403 — the console must not
 * confirm that another tenant's organization exists.
 */
final class ListOrganizationMembersController
{
    use TranslatesOrganizationRefusals;

    public function __construct(
        private readonly ListOrganizationMembers $listMembers,
    ) {}

    /**
     * List the members of an organization.
     *
     * Requires `members:read`. An organization outside the caller's
     * scope answers **404**, never 403 (ADR-0069 §2 / S-04).
     *
     * @throws OrganizationNotVisible the organization is outside the caller's scope (404)
     */
    public function __invoke(OperatorContext $operator, string $organizationId): JsonResponse|Response
    {
        // ADR-0069 §2 — outside the scope: 404, never 403; visible but
        // without members:read there: 403.
        $refusal = $this->organizationRefusal($operator, $organizationId, 'members:read');
        if ($refusal === 'not-found') {
            return ProblemDetails::make('organization-not-found', 'Organization not found', 404, 'No organization matches this identifier for this account.');
        }
        if ($refusal === 'forbidden') {
            return ProblemDetails::forbidden('The members:read permission is required in this organization.');
        }

        try {
            $members = $this->listMembers->list(
                new ListOrganizationMembersCommand($organizationId),
                $operator->scope,
            );
        } catch (OrganizationRefusal $refusal) {
            return $this->refusalResponse($refusal);
        }

        return new JsonResponse([
            'data' => array_map(static fn (OrganizationMemberView $member): array => [
                'operator_id' => $member->operatorId,
                'email' => $member->email,
                'name' => $member->name,
                'role' => $member->role,
                'joined_at' => $member->joinedAt->format(DATE_ATOM),
            ], $members->items),
            'meta' => [
                'total' => $members->total,
            ],
        ]);
    }
}
