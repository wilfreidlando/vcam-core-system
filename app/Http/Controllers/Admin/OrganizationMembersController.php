<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use Core\Identity\Contracts\ChangeOrganizationMemberRole;
use Core\Identity\Contracts\ChangeOrganizationMemberRoleCommand;
use Core\Identity\Contracts\GetOrganization;
use Core\Identity\Contracts\GetOrganizationCommand;
use Core\Identity\Contracts\InviteOrganizationMember;
use Core\Identity\Contracts\InviteOrganizationMemberCommand;
use Core\Identity\Contracts\ListOrganizationMembers;
use Core\Identity\Contracts\ListOrganizationMembersCommand;
use Core\Identity\Contracts\OperatorContext;
use Core\Identity\Contracts\OrganizationRefusal;
use Core\Identity\Contracts\OrganizationView;
use Core\Identity\Contracts\RevokeOrganizationMember;
use Core\Identity\Contracts\RevokeOrganizationMemberCommand;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * ADR-0069 §1/§4 — the members of an organization: list, invite,
 * revoke, change role.
 *
 * The web plane's own translation of the refusals `identity` publishes:
 * a refusal whose status is 404 is a 404 (ADR-0069 §2 — "never 403"
 * for a resource outside the scope), the others come back as a form
 * error the operator can read.
 */
final class OrganizationMembersController
{
    /**
     * The five roles ADR-0069 §1 fixes — the enum itself lives in
     * identity's Domain layer, which the console may not name (G3c).
     *
     * @var list<string>
     */
    private const ROLES = ['org_owner', 'org_admin', 'org_finance', 'org_support', 'org_viewer'];

    public function __construct(
        private readonly GetOrganization $getOrganization,
        private readonly ListOrganizationMembers $listMembers,
        private readonly InviteOrganizationMember $inviteMember,
        private readonly RevokeOrganizationMember $revokeMember,
        private readonly ChangeOrganizationMemberRole $changeRole,
    ) {}

    public function index(OperatorContext $operator, string $organizationId): Response
    {
        $organization = $this->resolveOrganization($organizationId, $operator, 'members:read');

        return $this->renderIndex($organization, $operator, null);
    }

    public function create(OperatorContext $operator, string $organizationId): Response
    {
        $organization = $this->resolveOrganization($organizationId, $operator, 'members:write');

        return response()->view('admin.organization-members.invite', [
            'title' => 'Inviter un membre — '.$organization->name,
            'organization' => $organization,
            'roles' => self::ROLES,
        ]);
    }

    public function store(Request $request, OperatorContext $operator, string $organizationId): Response|RedirectResponse
    {
        $organization = $this->resolveOrganization($organizationId, $operator, 'members:write');

        /** @var array{email: string, role: string} $data */
        // @phpstan-ignore-next-line — Laravel's Request::validate() return type is dynamic-array in stubs.
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'role' => ['required', 'string', 'in:'.implode(',', self::ROLES)],
        ]);

        try {
            $invitation = $this->inviteMember->invite(
                new InviteOrganizationMemberCommand(
                    organizationId: $organization->id,
                    email: $data['email'],
                    role: $data['role'],
                    actorOperatorId: $operator->operatorId,
                ),
                $operator->scope,
                actor: 'operator:'.$operator->operatorId,
                correlationId: null,
            );
        } catch (OrganizationRefusal $refusal) {
            return $this->refuse($refusal);
        }

        // The clear token is shown once, in THIS response, and nowhere
        // else (ADR-0069 §4, ADR-0081 S-07). It is rendered directly rather
        // than flashed into the session for a redirect: a flash would write
        // the clear token to the session store, where it would outlive the
        // response that carries it.
        return $this->renderIndex($organization, $operator, [
            'email' => $invitation->email,
            'role' => $invitation->role,
            'token' => $invitation->token,
            'expires_at' => $invitation->expiresAt->format(DATE_ATOM),
        ]);
    }

    public function revoke(OperatorContext $operator, string $organizationId, string $operatorId): RedirectResponse
    {
        $organization = $this->resolveOrganization($organizationId, $operator, 'members:write');

        try {
            $this->revokeMember->revoke(
                new RevokeOrganizationMemberCommand(
                    organizationId: $organization->id,
                    operatorId: $operatorId,
                    actorOperatorId: $operator->operatorId,
                ),
                $operator->scope,
                actor: 'operator:'.$operator->operatorId,
                correlationId: null,
            );
        } catch (OrganizationRefusal $refusal) {
            return $this->refuse($refusal);
        }

        return redirect()
            ->route('admin.organizations.members.index', ['organizationId' => $organization->id])
            ->with('status', 'Appartenance révoquée.');
    }

    public function updateRole(Request $request, OperatorContext $operator, string $organizationId, string $operatorId): RedirectResponse
    {
        $organization = $this->resolveOrganization($organizationId, $operator, 'members:write');

        /** @var array{role: string} $data */
        // @phpstan-ignore-next-line — Laravel's Request::validate() return type is dynamic-array in stubs.
        $data = $request->validate([
            'role' => ['required', 'string', 'in:'.implode(',', self::ROLES)],
        ]);

        try {
            $this->changeRole->changeRole(
                new ChangeOrganizationMemberRoleCommand(
                    organizationId: $organization->id,
                    operatorId: $operatorId,
                    role: $data['role'],
                    actorOperatorId: $operator->operatorId,
                ),
                $operator->scope,
                actor: 'operator:'.$operator->operatorId,
                correlationId: null,
            );
        } catch (OrganizationRefusal $refusal) {
            return $this->refuse($refusal);
        }

        return redirect()
            ->route('admin.organizations.members.index', ['organizationId' => $organization->id])
            ->with('status', 'Rôle mis à jour.');
    }

    /**
     * @param array{email: string, role: string, token: string|null, expires_at: string}|null $invitation
     */
    private function renderIndex(OrganizationView $organization, OperatorContext $operator, ?array $invitation): Response
    {
        $members = $this->listMembers->list(
            new ListOrganizationMembersCommand($organization->id),
            $operator->scope,
        );

        return response()->view('admin.organization-members.index', [
            'title' => 'Membres — '.$organization->name,
            'organization' => $organization,
            'members' => $members->items,
            'roles' => self::ROLES,
            'invitation' => $invitation,
            'canManage' => $operator->scopeFor('members:write')->allowsOrganization($organization->id),
        ]);
    }

    /**
     * ADR-0069 §2 — the two-level gate: an organization outside the
     * caller's scope answers **404**, never 403 (the console must not
     * confirm that another tenant's organization exists); a visible one
     * without `$permission` there answers **403**
     * (`OperatorContext::scopeFor()`).
     */
    private function resolveOrganization(string $organizationId, OperatorContext $operator, string $permission): OrganizationView
    {
        Validator::make(['organizationId' => $organizationId], ['organizationId' => 'uuid'])->validate();

        if (! $operator->scope->allowsOrganization($organizationId)) {
            abort(404, 'Organisation introuvable.');
        }

        try {
            $organization = $this->getOrganization->get(new GetOrganizationCommand($organizationId), $operator->scope);
        } catch (RuntimeException) {
            abort(404, 'Organisation introuvable.');
        }

        if (! $operator->scopeFor($permission)->allowsOrganization($organization->id)) {
            abort(403, sprintf('La permission %s est requise dans cette organisation.', $permission));
        }

        return $organization;
    }

    /**
     * A refusal keeps its meaning on this plane: 404 stays a 404 (S-04),
     * 403 stays a 403; a conflict or an invalid input (409/422) comes back
     * as a form error the operator can read.
     */
    private function refuse(OrganizationRefusal $refusal): RedirectResponse
    {
        if (in_array($refusal->httpStatus(), [403, 404], true)) {
            abort($refusal->httpStatus(), $refusal->getMessage());
        }

        return back()->withInput()->withErrors(['organization' => $this->say($refusal)]);
    }

    /**
     * The refusal in the console's language, without internal identifiers; the original message
     * stays for a case nobody has translated yet.
     */
    private function say(OrganizationRefusal $refusal): string
    {
        return match ($refusal->errorCode()) {
            'last-owner' => 'Une organisation garde toujours un propriétaire : nommez-en un autre avant de retirer ou de rétrograder celui-ci.',
            'already-member' => 'Cette personne est déjà membre de l\'organisation.',
            'privilege-escalation' => 'Vous ne pouvez pas attribuer un rôle supérieur au vôtre : seul un propriétaire attribue le rôle de propriétaire.',
            'organization-member-not-found' => 'Ce membre n\'existe pas ou n\'est plus dans l\'organisation.',
            default => $refusal->getMessage(),
        };
    }
}
