<?php

declare(strict_types=1);

namespace Core\Identity\Tests\Integration;

use Core\Identity\Contracts\AcceptOrganizationInvitation;
use Core\Identity\Contracts\AcceptOrganizationInvitationCommand;
use Core\Identity\Contracts\InvitationNotUsable;
use Core\Identity\Contracts\LastOrganizationOwner;
use Core\Identity\Contracts\OrganizationMemberManagementForbidden;
use Core\Identity\Contracts\RevokeOrganizationMember;
use Core\Identity\Contracts\RevokeOrganizationMemberCommand;
use Core\Identity\Domain\Model\InvitationToken;
use Core\Identity\Domain\Model\Operator;
use Core\Identity\Domain\Model\Organization;
use Core\Identity\Domain\Model\OrganizationInvitation;
use Core\Identity\Domain\Model\OrganizationMembership;
use Core\Identity\Domain\Model\OrganizationRole;
use Core\Identity\Domain\Port\OperatorRepository;
use Core\Identity\Domain\Port\OrganizationInvitationRepository;
use Core\Identity\Domain\Port\OrganizationMembershipRepository;
use Core\Identity\Domain\Port\OrganizationRepository;
use Core\Identity\OperatorRole;
use Core\Support\Access\AccessScope;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Throwable;

/**
 * ADR-0069 §4 and "Sécurité" — the two invariants that must hold under
 * concurrency, proven against real PostgreSQL row locks:
 *
 *  - an activation token is consumed **once**, even by two simultaneous
 *    acceptances;
 *  - two owners revoking each other at the same time never leave the
 *    organization without an owner.
 *
 * The interleaving is made deterministic with a second connection: it
 * plays the "other request", holds the lock mid-transaction, and the use
 * case under test must WAIT for it (proven with a short `lock_timeout`)
 * instead of deciding on the stale state; once the other request
 * commits, the use case decides on what it left behind.
 *
 * Fixtures are committed (the second connection must see them), so this
 * test does not run inside a transaction and deletes its own rows. No
 * audit row is written: every call that reaches the use case here ends
 * in a refusal, before the audit step (the audit log is append-only).
 */
final class OrganizationMembershipConcurrencyTest extends TestCase
{
    private Connection $other;

    private string $suffix = '';

    /** @var array<string, list<string>> table => ids to delete */
    private array $created = [];

    #[Test]
    public function two_simultaneous_acceptances_of_one_token_consume_it_exactly_once(): void
    {
        $organization = $this->organization();
        $owner = $this->account('owner', null);
        $this->member($organization, $owner, OrganizationRole::Owner);

        $now = new DateTimeImmutable;
        $token = InvitationToken::issue($now);
        $plain = (string) $token->revealPlainText();
        $invitation = new OrganizationInvitation(
            id: (string) Str::uuid(),
            organizationId: $organization->id,
            email: 'invitee-'.$this->suffix().'@acme.test',
            role: OrganizationRole::Viewer,
            token: $token,
            invitedBy: $owner->id,
            createdAt: $now,
        );
        app(OrganizationInvitationRepository::class)->save($invitation);
        $this->created['organization_invitations'][] = $invitation->id;

        // The other acceptance is mid-transaction: it holds the row.
        $this->other->beginTransaction();
        $this->other->select('SELECT id FROM organization_invitations WHERE token_hash = ? FOR UPDATE', [$token->hash()]);

        // This one must wait for it — not read the row as still usable.
        DB::statement("SET lock_timeout = '300ms'");
        $this->assertWaitsForTheLock(fn () => $this->accept($plain), 'organization_invitations');

        // The other acceptance commits.
        $this->other->update('UPDATE organization_invitations SET accepted_at = now() WHERE id = ?', [$invitation->id]);
        $this->other->commit();

        try {
            $this->accept($plain);
            self::fail('The second acceptance must be refused.');
        } catch (InvitationNotUsable $refusal) {
            self::assertSame('already_used', $refusal->reason);
        }

        self::assertNull(app(OperatorRepository::class)->findByEmail($invitation->email), 'No account was created by the losing acceptance.');
    }

    #[Test]
    public function two_owners_revoking_each_other_at_the_same_time_never_leave_zero_owners(): void
    {
        $organization = $this->organization();
        $alice = $this->account('alice', null);
        $bob = $this->account('bob', null);
        $platform = $this->account('platform', OperatorRole::PlatformAdmin);
        $this->member($organization, $alice, OrganizationRole::Owner);
        $this->member($organization, $bob, OrganizationRole::Owner);

        // Alice's request "revoke Bob" is mid-transaction: it locked the
        // owner rows (as `lockActiveOwners()` does) and revoked Bob.
        $this->other->beginTransaction();
        $this->other->select(
            "SELECT id FROM organization_memberships WHERE organization_id = ? AND role = 'org_owner' AND revoked_at IS NULL ORDER BY id FOR UPDATE",
            [$organization->id],
        );
        $this->other->update(
            'UPDATE organization_memberships SET revoked_at = now() WHERE organization_id = ? AND operator_id = ?',
            [$organization->id, $bob->id],
        );

        // Bob's request "revoke Alice" must wait: deciding now would see
        // two owners and remove the last one.
        DB::statement("SET lock_timeout = '300ms'");
        $this->assertWaitsForTheLock(fn () => $this->revoke($organization, $alice, actor: $bob), 'organization_memberships');

        $this->other->commit();

        // After Alice's request committed, Bob is no longer an owner…
        $this->expectRefusal(OrganizationMemberManagementForbidden::class, fn () => $this->revoke($organization, $alice, actor: $bob));
        // …and even the platform cannot remove the last owner.
        $this->expectRefusal(LastOrganizationOwner::class, fn () => $this->revoke($organization, $alice, actor: $platform));

        self::assertNotNull(app(OrganizationMembershipRepository::class)->findActive($organization->id, $alice->id));
    }

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--force' => true]);

        $this->suffix = bin2hex(random_bytes(4));

        $default = config('database.default');
        self::assertIsString($default);
        config(['database.connections.pgsql_concurrent' => config('database.connections.'.$default)]);
        $this->other = DB::connection('pgsql_concurrent');
    }

    protected function tearDown(): void
    {
        if ($this->other->transactionLevel() > 0) {
            $this->other->rollBack();
        }
        $this->other->disconnect();

        DB::statement('SET lock_timeout TO DEFAULT');

        foreach (['organization_invitations', 'organization_memberships', 'operators', 'organizations'] as $table) {
            if (($this->created[$table] ?? []) !== []) {
                DB::table($table)->whereIn('id', $this->created[$table])->delete();
            }
        }
        DB::table('organization_memberships')->whereIn('operator_id', $this->created['operators'] ?? [])->delete();
        DB::table('operators')->whereIn('email', ['invitee-'.$this->suffix().'@acme.test'])->delete();

        parent::tearDown();
    }

    /**
     * The use case must wait for the concurrent request's lock **at its
     * first read** — the `SELECT … FOR UPDATE` on `$table` — before it
     * decides anything or writes anything. Blocking later (on its own
     * final UPDATE) would mean it decided on the stale state and, once the
     * other request commits, would overwrite it: exactly the race.
     */
    private function assertWaitsForTheLock(callable $work, string $table): void
    {
        try {
            $work();
            self::fail('The use case must wait for the row lock held by the concurrent request.');
        } catch (QueryException $exception) {
            // 55P03 lock_not_available — raised by `lock_timeout`.
            self::assertSame('55P03', $exception->getCode(), $exception->getMessage());

            $sql = strtolower($exception->getSql());
            self::assertStringStartsWith('select', $sql, 'The wait must happen on the locking read, not on a write: '.$sql);
            self::assertStringContainsString('for update', $sql);
            self::assertStringContainsString($table, $sql);
        }
    }

    /**
     * @param class-string<Throwable> $refusal
     */
    private function expectRefusal(string $refusal, callable $work): void
    {
        try {
            $work();
            self::fail('Expected '.$refusal);
        } catch (Throwable $thrown) {
            self::assertInstanceOf($refusal, $thrown);
        }
    }

    private function accept(string $token): void
    {
        app(AcceptOrganizationInvitation::class)->accept(new AcceptOrganizationInvitationCommand($token, 'Invitee', 'a chosen passphrase'));
    }

    private function revoke(Organization $organization, Operator $target, Operator $actor): void
    {
        app(RevokeOrganizationMember::class)->revoke(
            new RevokeOrganizationMemberCommand($organization->id, $target->id, $actor->id),
            AccessScope::platform(),
            actor: 'operator:'.$actor->id,
            correlationId: null,
        );
    }

    private function organization(): Organization
    {
        $organization = Organization::create((string) Str::uuid(), 'Concurrency '.$this->suffix());
        app(OrganizationRepository::class)->save($organization);
        $this->created['organizations'][] = $organization->id;

        return $organization;
    }

    private function account(string $name, ?OperatorRole $role): Operator
    {
        $operator = new Operator(
            id: (string) Str::uuid(),
            email: $name.'-'.$this->suffix().'@concurrency.test',
            name: $name,
            passwordHash: Operator::hashPassword('irrelevant passphrase'),
            role: $role,
            mfaSecret: null,
            mfaEnabled: false,
            mfaLastVerifiedTimestep: null,
            revokedAt: null,
            createdAt: new DateTimeImmutable,
        );
        app(OperatorRepository::class)->create($operator);
        $this->created['operators'][] = $operator->id;

        return $operator;
    }

    private function member(Organization $organization, Operator $operator, OrganizationRole $role): void
    {
        $membership = new OrganizationMembership((string) Str::uuid(), $organization->id, $operator->id, $role, new DateTimeImmutable);
        app(OrganizationMembershipRepository::class)->save($membership);
        $this->created['organization_memberships'][] = $membership->id;
    }

    private function suffix(): string
    {
        return $this->suffix;
    }
}
