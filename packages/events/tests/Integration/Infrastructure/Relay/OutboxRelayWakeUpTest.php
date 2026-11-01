<?php

declare(strict_types=1);

namespace Core\Events\Tests\Integration\Infrastructure\Relay;

use Core\Events\Contracts\DomainEvent;
use Core\Events\Contracts\EventOutbox;
use Core\Events\Contracts\RelayOutboxEvents;
use Core\Events\Infrastructure\Relay\WakeOutboxRelayJob;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** ADR-0090 §1 — the relay is woken after the commit, and a row is published once. */
final class OutboxRelayWakeUpTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function writing_an_event_queues_one_wake_up_after_the_commit_and_the_flag_turns_it_off(): void
    {
        Queue::fake();

        config(['events.relay.on_commit' => false]);
        app(EventOutbox::class)->append($this->event('11111111-1111-7111-8111-111111111111'));
        Queue::assertNotPushed(WakeOutboxRelayJob::class);

        config(['events.relay.on_commit' => true]);
        app(EventOutbox::class)->append($this->event('22222222-2222-7222-8222-222222222222'));
        Queue::assertPushed(WakeOutboxRelayJob::class, 1);
    }

    #[Test]
    public function a_row_already_published_is_not_published_again_by_a_second_pass(): void
    {
        config(['events.relay.on_commit' => false]);
        app(EventOutbox::class)->append($this->event('33333333-3333-7333-8333-333333333333'));

        // The relay publishes every pending row, whoever wrote it. The
        // rows another test left behind are not this test's business:
        // mark them published so the count below is this test's row only.
        // (The update is rolled back with the test's transaction.)
        DB::table('event_outbox')
            ->whereNull('published_at')
            ->where('id', '!=', '33333333-3333-7333-8333-333333333333')
            ->update(['published_at' => now()]);

        $relay = app(RelayOutboxEvents::class);

        self::assertSame(1, $relay->relay(10));
        self::assertSame(0, $relay->relay(10), 'The second pass finds nothing left to publish.');
        self::assertNotNull(DB::table('event_outbox')->where('id', '33333333-3333-7333-8333-333333333333')->value('published_at'));
    }

    private function event(string $id): DomainEvent
    {
        return new DomainEvent(
            id: $id,
            projectId: '0197a000-0000-7000-8000-000000000001',
            type: 'test.happened',
            payload: ['n' => 1],
            correlationId: null,
            occurredAt: new DateTimeImmutable('2026-10-29T10:00:00Z'),
        );
    }
}
