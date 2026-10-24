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
}
