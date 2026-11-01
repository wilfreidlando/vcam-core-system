<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use Core\Identity\Contracts\CreateOrganization;
use Core\Identity\Contracts\CreateOrganizationCommand;
use Core\Identity\Domain\Model\OrganizationMembership;
use Core\Identity\Domain\Model\OrganizationRole;
use Core\Identity\Domain\Port\OperatorTokenIssuer;
use Core\Identity\Domain\Port\OrganizationMembershipRepository;
use Core\Identity\OperatorRole;
use Core\Identity\Presentation\Http\Middleware\ResolveOperatorMiddleware;
use DateTimeImmutable;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\OperatorFactory;
use Tests\TestCase;

/**
 * ADR-0069 §1, §4 and "Sécurité" over the real HTTP routes — the JSON
 * plane (`/v1/admin/...`, bearer token) and the web console
 * (`/admin/...`, session).
 *
 * The full invitation round trip goes through the existing MFA flow
 * (ADR-0044/0046): accept → `mfa/enroll` → `mfa/confirm` → bearer token,
 * exactly what an invitee does.
 */
final class OrganizationMembersTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_platform_admin_creates_an_organization_whose_owner_is_invited_activates_and_manages_the_members(): void
    {
        $platform = $this->platformAccount('root@platform.test');

        $organizationId = $this->api($platform['token'])
            ->postJson('/v1/admin/organizations', ['name' => 'Acme'])
            ->assertCreated()
            ->json('id');
        self::assertIsString($organizationId);

        // The platform invites the first owner: the clear token comes back
        // once, in this response.
        $invite = $this->api($platform['token'])
            ->postJson("/v1/admin/organizations/{$organizationId}/invitations", ['email' => 'Owner@Acme.test', 'role' => 'org_owner'])
            ->assertCreated()
            ->assertJsonPath('email', 'owner@acme.test')
            ->assertJsonPath('role', 'org_owner');
        $token = $invite->json('token');
        self::assertIsString($token);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $token);

        // Only the sha256 hash is stored; the clear token is nowhere in the
        // database — not in the invitation row, not in the audit trail.
        $row = DB::table('organization_invitations')->where('id', $invite->json('id'))->first();
        self::assertNotNull($row);
        self::assertSame(hash('sha256', $token), $row->token_hash);
        self::assertStringNotContainsString($token, (string) json_encode($row));

        $owner = $this->activate($token, 'Owner Name', 'owner chosen passphrase');

        // The new owner, signed in through the regular MFA flow, sees its
        // organization's members — itself.
        $this->api($owner['token'])
            ->getJson("/v1/admin/organizations/{$organizationId}/members")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.email', 'owner@acme.test')
            ->assertJsonPath('data.0.role', 'org_owner');

        // A single use: the same token is refused afterwards.
        $this->postJson('/v1/admin/organization-invitations/accept', ['token' => $token, 'name' => 'x', 'password' => 'another passphrase'])
            ->assertStatus(409)
            ->assertJsonPath('type', 'https://errors.core-system.internal/invitation-not-usable');

        // The owner invites an admin, who activates too.
        $adminToken = $this->api($owner['token'])
            ->postJson("/v1/admin/organizations/{$organizationId}/invitations", ['email' => 'admin@acme.test', 'role' => 'org_admin'])
            ->assertCreated()
            ->json('token');
        self::assertIsString($adminToken);

        // Invite twice while pending: refused.
        $this->api($owner['token'])
            ->postJson("/v1/admin/organizations/{$organizationId}/invitations", ['email' => 'admin@acme.test', 'role' => 'org_viewer'])
            ->assertStatus(409)
            ->assertJsonPath('type', 'https://errors.core-system.internal/invitation-already-exists');

        $admin = $this->activate($adminToken, 'Admin Name', 'admin chosen passphrase');

        $this->api($owner['token'])
            ->getJson("/v1/admin/organizations/{$organizationId}/members")
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        // ADR-0069 "Sécurité" — an org_admin does not grant org_owner,
        // neither to a newcomer nor to itself.
        $this->api($admin['token'])
            ->postJson("/v1/admin/organizations/{$organizationId}/invitations", ['email' => 'new@acme.test', 'role' => 'org_owner'])
            ->assertForbidden()
            ->assertJsonPath('type', 'https://errors.core-system.internal/privilege-escalation');
        $this->api($admin['token'])
            ->putJson("/v1/admin/organizations/{$organizationId}/members/{$admin['id']}", ['role' => 'org_owner'])
            ->assertForbidden()
            ->assertJsonPath('type', 'https://errors.core-system.internal/privilege-escalation');
        // ... nor revokes the owner.
        $this->api($admin['token'])
            ->postJson("/v1/admin/organizations/{$organizationId}/members/{$owner['id']}/revoke")
            ->assertForbidden();

        // Nobody grants a platform role through this path.
        $this->api($owner['token'])
            ->putJson("/v1/admin/organizations/{$organizationId}/members/{$admin['id']}", ['role' => 'platform_admin'])
            ->assertForbidden()
            ->assertJsonPath('type', 'https://errors.core-system.internal/platform-role-forbidden');

        // ADR-0069 — the last owner stays: revoking or demoting it is a
        // 409 last-owner, whoever asks.
        $this->api($owner['token'])
            ->postJson("/v1/admin/organizations/{$organizationId}/members/{$owner['id']}/revoke")
            ->assertStatus(409)
            ->assertJsonPath('type', 'https://errors.core-system.internal/last-owner');
        $this->api($platform['token'])
            ->putJson("/v1/admin/organizations/{$organizationId}/members/{$owner['id']}", ['role' => 'org_viewer'])
            ->assertStatus(409)
            ->assertJsonPath('type', 'https://errors.core-system.internal/last-owner');

        // A role change by the owner goes through, and is audited.
        $this->api($owner['token'])
            ->putJson("/v1/admin/organizations/{$organizationId}/members/{$admin['id']}", ['role' => 'org_finance'])
            ->assertNoContent();

        // ADR-0069, symétrie du dernier propriétaire — the `org_finance`
        // role carries `money-exceptions:write`: it is the second pair of
        // eyes on the money. The LAST one cannot be revoked either: the
        // organization would have nobody able to resolve an exception.
        $this->api($owner['token'])
            ->postJson("/v1/admin/organizations/{$organizationId}/members/{$admin['id']}/revoke")
            ->assertStatus(409)
            ->assertJsonPath('type', 'https://errors.core-system.internal/last-finance');

        // A second `org_finance` makes the revocation possible again.
        $secondFinanceToken = $this->api($owner['token'])
            ->postJson("/v1/admin/organizations/{$organizationId}/invitations", ['email' => 'finance@acme.test', 'role' => 'org_finance'])
            ->assertCreated()
            ->json('token');
        self::assertIsString($secondFinanceToken);
        $this->activate($secondFinanceToken, 'Finance Name', 'finance chosen passphrase');

        $this->api($owner['token'])
            ->postJson("/v1/admin/organizations/{$organizationId}/members/{$admin['id']}/revoke")
            ->assertNoContent();

        // Every step is in the organization's journal, and no token is.
        $entries = DB::table('audit_log')->where('organization_id', $organizationId)->get();
        $actions = $entries->pluck('action')->all();
        foreach (['organization.member_invited', 'organization.invitation_accepted', 'organization.member_role_changed', 'organization.member_revoked'] as $action) {
            self::assertContains($action, $actions);
        }
        $journal = (string) json_encode($entries->all());
        self::assertStringNotContainsString($token, $journal);
        self::assertStringNotContainsString($adminToken, $journal);
        self::assertStringNotContainsString(hash('sha256', $token), $journal);
    }

    #[Test]
    public function an_existing_account_joins_with_its_current_password_which_is_never_replaced(): void
    {
        $platform = $this->platformAccount('root@platform.test');
        $organizationId = $this->organization('Acme');

        $consultant = OperatorFactory::create('consultant@example.test', null);

        $token = $this->api($platform['token'])
            ->postJson("/v1/admin/organizations/{$organizationId}/invitations", ['email' => 'consultant@example.test', 'role' => 'org_viewer'])
            ->assertCreated()
            ->json('token');
        self::assertIsString($token);

        // A token holder cannot set the password of the existing account.
        $this->postJson('/v1/admin/organization-invitations/accept', ['token' => $token, 'password' => 'attacker picked this'])
            ->assertStatus(422)
            ->assertJsonPath('type', 'https://errors.core-system.internal/invitation-account-password-invalid');

        $this->postJson('/v1/admin/organization-invitations/accept', ['token' => $token, 'password' => OperatorFactory::PASSWORD])
            ->assertCreated()
            ->assertJsonPath('operator_id', $consultant)
            ->assertJsonPath('account_created', false)
            ->assertJsonPath('purpose', 'mfa_enrollment');

        // The original password still signs in; the attempted one does not.
        $this->postJson('/v1/operators/login', ['email' => 'consultant@example.test', 'password' => OperatorFactory::PASSWORD])->assertOk();
        $this->postJson('/v1/operators/login', ['email' => 'consultant@example.test', 'password' => 'attacker picked this'])->assertUnauthorized();
    }

    #[Test]
    public function an_account_of_organization_a_gets_404_on_every_member_route_of_organization_b(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);

        $organizationA = $this->organization('A');
        $organizationB = $this->organization('B');
        $ownerA = $this->organizationAccount('owner@a.test', $organizationA, OrganizationRole::Owner);
        $ownerB = $this->organizationAccount('owner@b.test', $organizationB, OrganizationRole::Owner);
        $memberB = $this->organizationAccount('member@b.test', $organizationB, OrganizationRole::Viewer);

        $json = [
            ['GET', "/v1/admin/organizations/{$organizationB}/members", []],
            ['POST', "/v1/admin/organizations/{$organizationB}/invitations", ['email' => 'x@a.test', 'role' => 'org_viewer']],
            ['POST', "/v1/admin/organizations/{$organizationB}/members/{$memberB['id']}/revoke", []],
            ['PUT', "/v1/admin/organizations/{$organizationB}/members/{$memberB['id']}", ['role' => 'org_owner']],
        ];
        foreach ($json as [$method, $uri, $body]) {
            $this->api($ownerA['token'])->json($method, $uri, $body)
                ->assertNotFound()
                ->assertDontSee('member@b.test');
        }

        // An organization that does not exist answers exactly the same.
        $unknown = (string) Str::uuid();
        $this->api($ownerA['token'])->getJson("/v1/admin/organizations/{$unknown}/members")->assertNotFound();

        $web = [
            ['GET', "/admin/organizations/{$organizationB}/members", []],
            ['GET', "/admin/organizations/{$organizationB}/members/invite", []],
            ['POST', "/admin/organizations/{$organizationB}/members", ['email' => 'x@a.test', 'role' => 'org_viewer']],
            ['POST', "/admin/organizations/{$organizationB}/members/{$memberB['id']}/revoke", []],
            ['PUT', "/admin/organizations/{$organizationB}/members/{$memberB['id']}/role", ['role' => 'org_owner']],
        ];
        foreach ($web as [$method, $uri, $body]) {
            $this->web($ownerA['token'])->call($method, $uri, $body)->assertNotFound();
        }

        // Nothing of B was touched on the way.
        $memberships = app(OrganizationMembershipRepository::class);
        self::assertSame(OrganizationRole::Viewer, $memberships->findActive($organizationB, $memberB['id'])?->role);
        self::assertNotNull($memberships->findActive($organizationB, $ownerB['id']));
        self::assertSame(0, DB::table('organization_invitations')->where('organization_id', $organizationB)->count());
    }

    #[Test]
    public function a_member_without_the_permission_gets_403_on_its_own_organization(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);

        $organizationId = $this->organization('Acme');
        $this->organizationAccount('owner@acme.test', $organizationId, OrganizationRole::Owner);
        $viewer = $this->organizationAccount('viewer@acme.test', $organizationId, OrganizationRole::Viewer);

        $this->api($viewer['token'])->getJson("/v1/admin/organizations/{$organizationId}/members")->assertForbidden();
        $this->api($viewer['token'])
            ->postJson("/v1/admin/organizations/{$organizationId}/invitations", ['email' => 'x@acme.test', 'role' => 'org_viewer'])
            ->assertForbidden();
        $this->web($viewer['token'])->get("/admin/organizations/{$organizationId}/members/invite")->assertForbidden();
    }

    #[Test]
    public function an_account_without_a_platform_role_cannot_create_an_organization(): void
    {
        $organizationId = $this->organization('Acme');
        $owner = $this->organizationAccount('owner@acme.test', $organizationId, OrganizationRole::Owner);

        $this->api($owner['token'])
            ->postJson('/v1/admin/organizations', ['name' => 'Self-service attempt'])
            ->assertForbidden();

        self::assertSame(0, DB::table('organizations')->where('name', 'Self-service attempt')->count());
    }

    #[Test]
    public function the_web_console_shows_the_activation_token_once_and_lists_the_member_after_activation(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);

        $organizationId = $this->organization('Acme');
        $owner = $this->organizationAccount('owner@acme.test', $organizationId, OrganizationRole::Owner);

        $this->web($owner['token'])->get("/admin/organizations/{$organizationId}/members")
            ->assertOk()
            ->assertSee('owner@acme.test');

        $created = $this->web($owner['token'])->post("/admin/organizations/{$organizationId}/members", [
            'email' => 'viewer@acme.test',
            'role' => 'org_viewer',
        ]);
        $created->assertOk();
        $token = $this->extractToken($created);

        // Not flashed to the session: the next page does not show it again.
        $this->web($owner['token'])->get("/admin/organizations/{$organizationId}/members")
            ->assertOk()
            ->assertDontSee($token);

        $this->activate($token, 'Viewer', 'viewer chosen passphrase');

        $this->web($owner['token'])->get("/admin/organizations/{$organizationId}/members")
            ->assertOk()
            ->assertSee('viewer@acme.test');

        // The last owner cannot be revoked from the console either.
        $this->web($owner['token'])
            ->from("/admin/organizations/{$organizationId}/members")
            ->post("/admin/organizations/{$organizationId}/members/{$owner['id']}/revoke")
            ->assertRedirect()
            ->assertSessionHasErrors('organization');
        self::assertNotNull(app(OrganizationMembershipRepository::class)->findActive($organizationId, $owner['id']));
    }

    /**
     * The test database is shared with other workstreams: the schema is
     * brought up with the additive `migrate`, never `migrate:fresh`, and
     * every row written here is rolled back with the test's transaction.
     */
    protected function refreshDatabase(): void
    {
        Artisan::call('migrate', ['--force' => true]);

        RefreshDatabaseState::$migrated = true;

        $this->beginDatabaseTransaction();
    }

    /**
     * Accepts an invitation and completes the existing MFA enrollment
     * flow; returns the activated account's id and bearer token.
     *
     * @return array{id: string, token: string}
     */
    private function activate(string $invitationToken, string $name, string $password): array
    {
        $this->flushHeaders();

        $accepted = $this->postJson('/v1/admin/organization-invitations/accept', [
            'token' => $invitationToken,
            'name' => $name,
            'password' => $password,
        ])->assertCreated()
            ->assertJsonPath('account_created', true)
            ->assertJsonPath('purpose', 'mfa_enrollment')
            ->assertJsonMissingPath('access_token');

        $operatorId = $accepted->json('operator_id');
        $challenge = $accepted->json('challenge_token');
        self::assertIsString($operatorId);
        self::assertIsString($challenge);

        // The invitee's own password signs in — nothing was chosen for them.
        $this->postJson('/v1/operators/login', ['email' => $accepted->json('email'), 'password' => $password])->assertOk();

        $enrollment = $this->postJson('/v1/operators/mfa/enroll', ['challenge_token' => $challenge])->assertOk();
        $secret = $enrollment->json('secret');
        $confirmToken = $enrollment->json('confirm_challenge_token');
        self::assertIsString($secret);
        self::assertIsString($confirmToken);

        $session = $this->postJson('/v1/operators/mfa/confirm', [
            'challenge_token' => $confirmToken,
            'code' => (new Google2FA)->getCurrentOtp($secret),
        ])->assertCreated();

        $accessToken = $session->json('access_token');
        self::assertIsString($accessToken);

        return ['id' => $operatorId, 'token' => $accessToken];
    }

    /**
     * @return array{id: string, token: string}
     */
    private function platformAccount(string $email): array
    {
        $created = OperatorFactory::create($email, OperatorRole::PlatformAdmin);

        return ['id' => $created, 'token' => app(OperatorTokenIssuer::class)->issue($created, 'test')];
    }

    /**
     * An account with no platform role and one membership.
     *
     * @return array{id: string, token: string}
     */
    private function organizationAccount(string $email, string $organizationId, OrganizationRole $role): array
    {
        $created = OperatorFactory::create($email, null);

        app(OrganizationMembershipRepository::class)->save(new OrganizationMembership(
            id: (string) Str::uuid(),
            organizationId: $organizationId,
            operatorId: $created,
            role: $role,
            createdAt: new DateTimeImmutable,
        ));

        return ['id' => $created, 'token' => app(OperatorTokenIssuer::class)->issue($created, 'test')];
    }

    private function organization(string $name): string
    {
        return app(CreateOrganization::class)->create(new CreateOrganizationCommand($name), actor: 'service:test', correlationId: null)->id;
    }

    private function api(string $bearerToken): self
    {
        return $this->flushHeaders()->withToken($bearerToken);
    }

    private function web(string $bearerToken): self
    {
        return $this->flushHeaders()->withSession([ResolveOperatorMiddleware::SESSION_KEY => $bearerToken]);
    }

    /**
     * @param TestResponse<\Illuminate\Http\Response> $response
     */
    private function extractToken(TestResponse $response): string
    {
        // @phpstan-ignore-next-line — TestResponse::getContent() is a dynamic proxy to the underlying Symfony Response.
        $content = $response->getContent();
        $matched = preg_match('/<code[^>]*>([A-Za-z0-9_-]{43})<\/code>/', is_string($content) ? $content : '', $matches);
        self::assertSame(1, $matched, 'The activation token is shown on the creation response.');

        return $matches[1];
    }
}
