<?php

declare(strict_types=1);

namespace Core\Events\Tests\Integration\Infrastructure\Relay;

use Core\Events\Contracts\DomainEvent;
use Core\Events\Contracts\EventOutbox;
use Core\Events\Contracts\OutboxEventPublished;
use Core\Events\Contracts\RelayOutboxEvents;
use Core\Support\Observability\TraceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ADR-0040 §1: the relay is the single reader of `event_outbox`. This
 * verifies both halves of that contract — it republishes what was
 * pending, and it never touches a row twice.
 */
final class OutboxRelayTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_republishes_every_unpublished_row_and_marks_it_published(): void
    {
        Event::fake([OutboxEventPublished::class]);

        $eventId = (string) Str::uuid();
        app(EventOutbox::class)->append(new DomainEvent(
            id: $eventId,
            projectId: (string) Str::uuid(),
            type: 'test.event',
            payload: ['k' => 'v'],
            correlationId: null,
            occurredAt: now()->toImmutable(),
        ));

        $relayed = app(RelayOutboxEvents::class)->relay(100);

        self::assertSame(1, $relayed);
        Event::assertDispatched(
            OutboxEventPublished::class,
            static fn (OutboxEventPublished $published): bool => $published->event->id === $eventId,
        );

        $row = DB::table('event_outbox')->where('id', $eventId)->first();
        self::assertNotNull($row);
        self::assertNotNull($row->published_at);
    }

    #[Test]
    public function a_second_run_finds_nothing_left_to_relay(): void
    {
        Event::fake([OutboxEventPublished::class]);

        app(EventOutbox::class)->append(new DomainEvent(
            id: (string) Str::uuid(),
            projectId: (string) Str::uuid(),
            type: 'test.event',
            payload: [],
            correlationId: null,
            occurredAt: now()->toImmutable(),
        ));

        app(RelayOutboxEvents::class)->relay(100);
        $second = app(RelayOutboxEvents::class)->relay(100);

        self::assertSame(0, $second);
    }

    #[Test]
    public function it_respects_the_batch_limit(): void
    {
        Event::fake([OutboxEventPublished::class]);

        $projectId = (string) Str::uuid();
        for ($i = 0; $i < 3; $i++) {
            app(EventOutbox::class)->append(new DomainEvent(
                id: (string) Str::uuid(),
                projectId: $projectId,
                type: 'test.event',
                payload: [],
                correlationId: null,
                occurredAt: now()->toImmutable(),
            ));
        }

        $relayed = app(RelayOutboxEvents::class)->relay(2);

        self::assertSame(2, $relayed);
    }

    #[Test]
    public function the_writing_requests_correlation_and_trace_ids_survive_the_outbox(): void
    {
        // ADR-0059: a use case appends without a correlation id; the row
        // still carries the request's, and the relay restores both ids
        // into the Context its listeners (and their jobs) inherit.
        TraceContext::begin('corr-from-request', str_repeat('a', 32));

        $eventId = (string) Str::uuid();
        app(EventOutbox::class)->append(new DomainEvent(
            id: $eventId,
            projectId: (string) Str::uuid(),
            type: 'test.event',
            payload: [],
            correlationId: null,
            occurredAt: now()->toImmutable(),
        ));

        $row = DB::table('event_outbox')->where('id', $eventId)->first();
        self::assertNotNull($row);
        self::assertSame('corr-from-request', $row->correlation_id);
        self::assertSame(str_repeat('a', 32), $row->trace_id);

        // The relay runs later, in another process: nothing in Context.
        TraceContext::begin('scheduler-run', str_repeat('b', 32));
        $seen = [];
        Event::listen(OutboxEventPublished::class, function () use (&$seen): void {
            $seen = [TraceContext::correlationId(), TraceContext::traceId()];
        });

        app(RelayOutboxEvents::class)->relay(100);

        self::assertSame(['corr-from-request', str_repeat('a', 32)], $seen);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Other integration tests in the full suite deliberately commit
        // real, uncleaned rows outside RefreshDatabase (e.g.
        // WalletConcurrencyTest, for genuine multi-connection concurrency
        // testing) — their outbox writes are real, committed rows this
        // relay would otherwise count. Neutralise any backlog directly
        // (not via relay(), which would trigger real fan-out side effects
        // for unrelated events) so each test starts from an empty queue.
        DB::table('event_outbox')->whereNull('published_at')->update(['published_at' => now()]);
    }
}
