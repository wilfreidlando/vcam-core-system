<?php

declare(strict_types=1);

namespace Core\Webhook\Tests\Integration\Application;

use Core\Webhook\Contracts\RegisterWebhookEndpoint;
use Core\Webhook\Contracts\RegisterWebhookEndpointCommand;
use Core\Webhook\Contracts\TooManyWebhookEndpoints;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Throwable;

/**
 * Audit 2026-10-29 #17 / G-12 — the ceiling of active endpoints per
 * project is counted UNDER the project's lock, so two concurrent
 * registrations cannot both pass it.
 *
 * The interleaving is made deterministic with a second connection: it
 * plays the "other request", holds the project's lock mid-transaction,
 * and the registration under test must WAIT for it (proven with a short
 * `lock_timeout`) instead of deciding on the state it left behind.
 *
 * Fixtures are committed (the second connection must see them), so this
 * test does not run inside a transaction and deletes its own rows.
 */
final class WebhookEndpointCapConcurrencyTest extends TestCase
{
    private Connection $other;

    private string $projectId;

    #[Test]
    public function a_registration_waits_for_the_projects_lock_instead_of_counting_on_stale_state(): void
    {
        config(['webhook.max_active_endpoints_per_project' => 1]);

        // The project already holds its single allowed endpoint.
        $this->register();

        // The other request is mid-transaction: it holds the project's lock.
        $this->other->beginTransaction();
        $this->other->select(
            'SELECT project_id FROM webhook_project_locks WHERE project_id = ? FOR UPDATE',
            [$this->projectId],
        );

        // This one must wait for it — not read the count as it was.
        DB::statement("SET lock_timeout = '300ms'");
        $this->assertWaitsForTheLock(fn () => $this->register());

        // The other request commits.
        $this->other->commit();

        // Now the count is read under the lock: the ceiling refuses the
        // registration, and the endpoint the other request would have
        // created is not there — nothing slipped through.
        $this->expectRefusal(TooManyWebhookEndpoints::class, fn () => $this->register());

        self::assertSame(1, DB::table('webhook_endpoints')->where('project_id', $this->projectId)->count());
    }

    #[Test]
    public function a_project_below_the_ceiling_is_not_blocked_by_the_lock_of_another_project(): void
    {
        config(['webhook.max_active_endpoints_per_project' => 1]);

        // Another project holds the lock: it must not delay this one.
        $otherProject = (string) Str::uuid();
        $this->other->beginTransaction();
        $this->other->select(
            'SELECT project_id FROM webhook_project_locks WHERE project_id = ? FOR UPDATE',
            [$otherProject],
        );

        try {
            $this->register();
        } finally {
            $this->other->rollBack();
        }

        self::assertSame(1, DB::table('webhook_endpoints')->where('project_id', $this->projectId)->count());
    }

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--force' => true]);

        $this->projectId = (string) Str::uuid();

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

        DB::table('webhook_endpoints')->where('project_id', $this->projectId)->delete();
        DB::table('webhook_project_locks')->where('project_id', $this->projectId)->delete();

        parent::tearDown();
    }

    /**
     * The use case must wait for the concurrent request's lock **before**
     * it counts anything: the wait must happen on the locking read of
     * the project's anchor row, not later on a write — deciding now
     * would mean counting a state the other request is about to change.
     */
    private function assertWaitsForTheLock(callable $work): void
    {
        try {
            $work();
            self::fail('The registration must wait for the row lock held by the concurrent request.');
        } catch (QueryException $exception) {
            // 55P03 lock_not_available — raised by `lock_timeout`.
            self::assertSame('55P03', $exception->getCode(), $exception->getMessage());

            $sql = strtolower($exception->getSql());
            self::assertStringStartsWith('select', $sql, 'The wait must happen on the locking read, not on a write: '.$sql);
            self::assertStringContainsString('for update', $sql);
            self::assertStringContainsString('webhook_project_locks', $sql);
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

    private function register(): void
    {
        app(RegisterWebhookEndpoint::class)->register(new RegisterWebhookEndpointCommand(
            projectId: $this->projectId,
            url: 'https://example.com/hooks/inbound',
            events: ['payment.status_changed'],
        ));
    }
}
