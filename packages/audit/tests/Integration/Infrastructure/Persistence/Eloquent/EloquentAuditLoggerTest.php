<?php

declare(strict_types=1);

namespace Core\Audit\Tests\Integration\Infrastructure\Persistence\Eloquent;

use Core\Audit\Contracts\AuditEntry;
use Core\Audit\Contracts\AuditLogger;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The write side of the journal, against a real Postgres `audit_log` table.
 *
 * The correlation is the point of this test: the module's promise is an
 * audit journal "correlated end to end", and a caller that does not carry
 * a correlation id of its own — the console's use cases pass `null` — must
 * still land in the journal with the request's correlation id, so the
 * entry can be joined with the request's logs.
 */
final class EloquentAuditLoggerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_keeps_the_correlation_id_the_caller_carries(): void
    {
        $this->record(correlationId: 'corr-from-the-caller');

        self::assertSame('corr-from-the-caller', $this->storedCorrelationId());
    }

    #[Test]
    public function it_falls_back_on_the_request_correlation_id_when_the_caller_has_none(): void
    {
        Context::add('correlation_id', 'corr-from-the-request');

        try {
            $this->record(correlationId: null);

            self::assertSame('corr-from-the-request', $this->storedCorrelationId());
        } finally {
            Context::forget('correlation_id');
        }
    }

    #[Test]
    public function it_stores_null_when_there_is_no_correlation_id_at_all(): void
    {
        Context::forget('correlation_id');

        $this->record(correlationId: null);

        self::assertNull($this->storedCorrelationId());
    }

    private function record(?string $correlationId): void
    {
        app(AuditLogger::class)->record(new AuditEntry(
            actor: 'operator:1',
            action: 'probe.recorded',
            resource: 'probe:1',
            before: null,
            after: ['x' => 1],
            context: [],
            correlationId: $correlationId,
            occurredAt: new DateTimeImmutable('2026-10-26T10:00:00Z'),
        ));
    }

    private function storedCorrelationId(): ?string
    {
        $value = DB::table('audit_log')->where('action', 'probe.recorded')->value('correlation_id');

        if ($value === null) {
            return null;
        }

        self::assertIsString($value);

        return $value;
    }
}
