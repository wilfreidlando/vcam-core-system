<?php

declare(strict_types=1);

namespace Core\Audit\Tests\Integration;

use Core\Audit\Contracts\AuditDigests;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** ADR-0081 S-16 — the audit log is append-only and its sealed days are tamper-evident. */
final class AuditTamperEvidenceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_database_refuses_to_change_an_audit_entry(): void
    {
        $id = DB::table('audit_log')->insertGetId(['actor' => 'service:test', 'action' => 'probe.created', 'resource' => 'probe:1']);

        foreach (['update', 'delete', 'truncate'] as $operation) {
            $threw = null;
            try {
                DB::transaction(function () use ($id, $operation): void {
                    match ($operation) {
                        'update' => DB::table('audit_log')->where('id', $id)->update(['action' => 'probe.tampered']),
                        'delete' => DB::table('audit_log')->where('id', $id)->delete(),
                        default => DB::statement('TRUNCATE audit_log'),
                    };
                });
            } catch (QueryException $e) {
                $threw = $e;
            }

            self::assertNotNull($threw, "audit_log must refuse a {$operation}.");
            self::assertStringContainsString('append-only', $threw->getMessage());
        }
    }

    /**
     * The seal chain must be as immutable as the journal it seals: a
     * TRUNCATE of `audit_log_digests` wiped every sealed day at once and
     * the next seal started a fresh chain — the tamper evidence silently
     * re-baselined, with nothing left to notice it.
     */
    #[Test]
    public function the_database_refuses_to_wipe_the_sealed_days(): void
    {
        DB::table('audit_log_digests')->insert([
            'day' => '2026-10-25',
            'entries_count' => 3,
            'first_entry_id' => 1,
            'last_entry_id' => 3,
            'previous_digest' => null,
            'digest' => str_repeat('a', 64),
            'sealed_at' => '2026-10-26 00:05:00+00',
        ]);

        foreach (['update', 'delete', 'truncate'] as $operation) {
            $threw = null;
            try {
                DB::transaction(function () use ($operation): void {
                    match ($operation) {
                        'update' => DB::table('audit_log_digests')->where('day', '2026-10-25')->update(['entries_count' => 0]),
                        'delete' => DB::table('audit_log_digests')->where('day', '2026-10-25')->delete(),
                        default => DB::statement('TRUNCATE audit_log_digests'),
                    };
                });
            } catch (QueryException $e) {
                $threw = $e;
            }

            self::assertNotNull($threw, "audit_log_digests must refuse a {$operation}.");
            self::assertStringContainsString('append-only', $threw->getMessage());
        }
    }

    #[Test]
    public function a_sealed_day_verifies_and_an_altered_one_is_detected(): void
    {
        $yesterday = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-1 day')->setTime(10, 0);
        foreach (['created', 'updated'] as $i => $verb) {
            DB::table('audit_log')->insert([
                'actor' => 'operator:op-1',
                'action' => 'project.'.$verb,
                'resource' => 'project:p-1',
                'after' => json_encode(['n' => $i]),
                'created_at' => $yesterday->modify("+{$i} minutes")->format('Y-m-d H:i:sP'),
            ]);
        }

        $digests = app(AuditDigests::class);
        $sealed = $digests->sealThrough($yesterday);

        self::assertCount(1, $sealed);
        self::assertSame(2, $sealed[0]->entriesCount);
        self::assertTrue($digests->verify()->isIntact());
        self::assertSame([], $digests->sealThrough($yesterday), 'A sealed day is never sealed again.');

        // Owner rights can disable the trigger: the chain still tells.
        DB::statement('ALTER TABLE audit_log DISABLE TRIGGER audit_log_immutable');
        DB::table('audit_log')->where('action', 'project.updated')->update(['resource' => 'project:p-2']);
        DB::statement('ALTER TABLE audit_log ENABLE TRIGGER audit_log_immutable');

        $result = $digests->verify();
        self::assertFalse($result->isIntact());
        self::assertSame([$yesterday->format('Y-m-d')], $result->brokenDays);
    }

    #[Test]
    public function today_is_never_sealed(): void
    {
        DB::table('audit_log')->insert(['actor' => 'service:test', 'action' => 'probe.today', 'resource' => 'probe:2']);

        self::assertSame([], app(AuditDigests::class)->sealThrough(new DateTimeImmutable('now')));
    }

    #[Test]
    public function the_commands_seal_then_report_an_alteration_loudly(): void
    {
        $yesterday = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-1 day')->setTime(9, 0);
        DB::table('audit_log')->insert([
            'actor' => 'operator:op-1',
            'action' => 'operator.revoked',
            'resource' => 'operator:op-2',
            'created_at' => $yesterday->format('Y-m-d H:i:sP'),
        ]);

        self::assertSame(0, Artisan::call('audit:seal'));
        self::assertSame(0, Artisan::call('audit:verify'));

        DB::statement('ALTER TABLE audit_log DISABLE TRIGGER audit_log_immutable');
        DB::table('audit_log')->where('action', 'operator.revoked')->delete();
        DB::statement('ALTER TABLE audit_log ENABLE TRIGGER audit_log_immutable');

        $log = Log::spy();
        self::assertSame(1, Artisan::call('audit:verify'));
        $log->shouldHaveReceived('critical')->once();
    }

    /**
     * ADR-0069 §2 — the tenant attribution decides who may read an entry:
     * re-attributing one to another organization breaks the chain, while a
     * day sealed before the attribution existed still verifies.
     */
    #[Test]
    public function re_attributing_an_entry_to_another_tenant_is_detected(): void
    {
        $yesterday = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-1 day')->setTime(11, 0);
        DB::table('audit_log')->insert([
            'actor' => 'operator:op-1',
            'action' => 'api_key.issued',
            'resource' => 'api_key:k-1',
            'organization_id' => '11111111-1111-4111-8111-111111111111',
            'project_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'created_at' => $yesterday->format('Y-m-d H:i:sP'),
        ]);
        DB::table('audit_log')->insert([
            'actor' => 'operator:op-1',
            'action' => 'operator.logged_in',
            'resource' => 'operator:op-1',
            'created_at' => $yesterday->modify('+1 minute')->format('Y-m-d H:i:sP'),
        ]);

        $digests = app(AuditDigests::class);
        $digests->sealThrough($yesterday);
        self::assertTrue($digests->verify()->isIntact());

        DB::statement('ALTER TABLE audit_log DISABLE TRIGGER audit_log_immutable');
        DB::table('audit_log')->where('action', 'api_key.issued')->update(['organization_id' => '22222222-2222-4222-8222-222222222222']);
        DB::statement('ALTER TABLE audit_log ENABLE TRIGGER audit_log_immutable');

        self::assertSame([$yesterday->format('Y-m-d')], $digests->verify()->brokenDays);
    }
}
