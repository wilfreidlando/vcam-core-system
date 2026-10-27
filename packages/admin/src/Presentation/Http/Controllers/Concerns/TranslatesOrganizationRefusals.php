<?php

declare(strict_types=1);

namespace Core\Admin\Presentation\Http\Controllers\Concerns;

use Core\Identity\Contracts\OperatorContext;
use Core\Identity\Contracts\OrganizationRefusal;
use Core\Support\Http\ProblemDetails;
use Illuminate\Http\JsonResponse;

/**
 * ADR-0069 — the refusals `identity` publishes carry their own code and
 * their own status; the console only has to shape them into the
 * RFC 7807 problem+json every Core endpoint answers.
 *
 * The refusals live in `identity`'s `Contracts/` (not its Domain layer)
 * precisely so that this translation is possible without breaking G3c.
 */
trait TranslatesOrganizationRefusals
{
    /**
     * ADR-0069 §2 — the two-level gate of an organization's member routes:
     *  - outside the account's scope → **404** (S-04: never confirm that
     *    another tenant's organization exists);
     *  - visible, but the account does not hold `$permission` there →
     *    **403** (`OperatorContext::scopeFor()` — the scope narrowed to the
     *    organizations where the permission is held).
     *
     * The use case re-checks both from the stored state; this gate only
     * answers early and identically on both planes.
     *
     * A **reason**, not a response: the controller writes the problem
     * response itself, as a direct `ProblemDetails` return, so the API
     * documentation keeps inferring every response of the route — the
     * same rule as `AuthorizesInScope::projectRefusal()`.
     */
    private function organizationRefusal(OperatorContext $operator, string $organizationId, string $permission): ?string
    {
        if (! $operator->scope->allowsOrganization($organizationId)) {
            return 'not-found';
        }

        if (! $operator->scopeFor($permission)->allowsOrganization($organizationId)) {
            return 'forbidden';
        }

        return null;
    }

    private function refusalResponse(OrganizationRefusal $refusal): JsonResponse
    {
        return ProblemDetails::make(
            $refusal->errorCode(),
            $this->refusalTitle($refusal->errorCode()),
            $refusal->httpStatus(),
            $refusal->getMessage(),
        );
    }

    private function refusalTitle(string $code): string
    {
        return match ($code) {
            'last-owner' => 'The last owner of the organization cannot be removed',
            'privilege-escalation' => 'Privilege escalation is refused',
            'platform-role-forbidden' => 'A platform role cannot be granted through an organization',
            'organization-members-forbidden' => 'Managing the members of this organization is not allowed',
            'invalid-organization-role' => 'Invalid organization role',
            'invitation-already-exists' => 'An invitation is already pending for this email',
            'already-member' => 'Already a member of the organization',
            'organization-invitation-not-found' => 'Invitation not found',
            'invitation-not-usable' => 'This invitation can no longer be used',
            'operator-invitation-not-found' => 'Invitation not found',
            'operator-invitation-not-usable' => 'This invitation can no longer be used',
            'operator-invitation-already-exists' => 'An invitation is already pending for this email address',
            'invitation-password-too-weak' => 'The chosen password is too weak',
            'invitation-account-password-invalid' => 'The password of the existing account is required',
            'operator-already-exists' => 'The console account of this email is revoked',
            'organization-member-not-found' => 'Member not found',
            'organization-not-found' => 'Organization not found',
            default => 'The request was refused',
        };
    }
}
