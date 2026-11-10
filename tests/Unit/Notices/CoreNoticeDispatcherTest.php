<?php

declare(strict_types=1);

namespace Tests\Unit\Notices;

use Core\Events\Contracts\DomainEvent;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\CoreNoticeFixture;

/**
 * ADR-0118 §4 and §6 — what the Core sends, to whom, and how often.
 */
final class CoreNoticeDispatcherTest extends TestCase
{
    #[Test]
    public function a_declared_event_reaches_the_customer_with_a_readable_message(): void
    {
        $fixture = new CoreNoticeFixture(['usage.limit_reached']);

        $fixture->dispatcher()->dispatch(CoreNoticeFixture::usageLimitReached());

        self::assertCount(1, $fixture->sent);
        $command = $fixture->sent[0];
        self::assertSame('+237690000000', $command->phoneNumber);
        // A correlation reference, never the address itself (ADR-0041 §5).
        self::assertSame('core-notice:client-1', $command->toPartyRef);
        self::assertSame('whatsapp', $command->channel->value);
        self::assertStringContainsString('vous avez atteint la limite « messages »', $command->text);
        self::assertStringContainsString('Auto-école', $command->text);
        self::assertStringContainsString('(100/100)', $command->text);
    }

    #[Test]
    public function an_event_the_project_did_not_declare_is_not_sent(): void
    {
        $fixture = new CoreNoticeFixture([]);

        $fixture->dispatcher()->dispatch(CoreNoticeFixture::usageLimitReached());

        self::assertCount(0, $fixture->sent);
    }

    #[Test]
    public function an_event_outside_the_catalogue_is_not_sent(): void
    {
        $fixture = new CoreNoticeFixture(['payment.status_changed']);

        $fixture->dispatcher()->dispatch(new DomainEvent(
            id: 'event-1',
            projectId: 'project-1',
            type: 'payment.status_changed',
            payload: ['customer_ref' => 'client-1'],
            correlationId: null,
            occurredAt: new DateTimeImmutable('2026-11-01T00:00:00+00:00'),
        ));

        self::assertCount(0, $fixture->sent);
    }

    #[Test]
    public function a_customer_without_a_known_phone_number_is_not_written_to(): void
    {
        $fixture = new CoreNoticeFixture(['usage.limit_reached'], null);

        $fixture->dispatcher()->dispatch(CoreNoticeFixture::usageLimitReached());

        self::assertCount(0, $fixture->sent);
    }

    #[Test]
    public function two_notices_of_the_same_event_in_the_same_window_carry_the_same_key(): void
    {
        $fixture = new CoreNoticeFixture(['usage.limit_reached']);
        $dispatcher = $fixture->dispatcher();

        $dispatcher->dispatch(CoreNoticeFixture::usageLimitReached());
        $dispatcher->dispatch(CoreNoticeFixture::usageLimitReached());

        // The key is what makes the second one a replay of the first
        // notification — and never a second message (ADR-0118 §6).
        self::assertCount(2, $fixture->sent);
        self::assertSame($fixture->sent[0]->idempotencyKey, $fixture->sent[1]->idempotencyKey);
        self::assertSame(
            'core-notice:usage.limit_reached:client-1:'
            .'meter|messages|2026-11-01T00:00:00+00:00|2026-11-30T23:59:59+00:00|limit|100',
            $fixture->sent[0]->idempotencyKey,
        );
    }

    #[Test]
    public function another_window_is_another_notice(): void
    {
        $fixture = new CoreNoticeFixture(['usage.limit_reached']);
        $dispatcher = $fixture->dispatcher();

        $dispatcher->dispatch(CoreNoticeFixture::usageLimitReached());
        $dispatcher->dispatch(CoreNoticeFixture::usageLimitReached('2026-12-31T23:59:59+00:00'));

        self::assertNotSame($fixture->sent[0]->idempotencyKey, $fixture->sent[1]->idempotencyKey);
    }

    /**
     * ADR-0120 — a plan can carry several metered features, and two of
     * them can be exhausted in the very same period. The second one is
     * another fact: the customer must be told about it too.
     */
    #[Test]
    public function another_meter_is_another_notice(): void
    {
        $fixture = new CoreNoticeFixture(['usage.limit_reached']);
        $dispatcher = $fixture->dispatcher();

        $dispatcher->dispatch($this->usageEvent(meter: 'messages'));
        $dispatcher->dispatch($this->usageEvent(meter: 'sms'));

        self::assertNotSame($fixture->sent[0]->idempotencyKey, $fixture->sent[1]->idempotencyKey);
    }

    /**
     * ADR-0120 — a pack bought in the middle of the period raises the
     * limit (ADR-0117). Reaching *that* limit is another fact: the
     * customer must be told that the quota he paid for is spent too.
     */
    #[Test]
    public function a_limit_raised_by_a_pack_is_another_notice(): void
    {
        $fixture = new CoreNoticeFixture(['usage.limit_reached']);
        $dispatcher = $fixture->dispatcher();

        $dispatcher->dispatch($this->usageEvent(used: 100, limit: 100));
        $dispatcher->dispatch($this->usageEvent(used: 150, limit: 150));

        self::assertNotSame($fixture->sent[0]->idempotencyKey, $fixture->sent[1]->idempotencyKey);
    }

    /**
     * ADR-0120 — un seuil franchi une fois puis re-franchi parce qu'un
     * pack a relevé la limite reste **le même** message : le texte ne dit
     * que le pourcentage (« 80 % »), il ne distingue pas la limite
     * d'avant de celle d'après. Deux WhatsApp identiques ne s'envoient
     * pas.
     */
    #[Test]
    public function a_threshold_crossed_again_by_a_pack_is_the_same_notice(): void
    {
        $fixture = new CoreNoticeFixture(['usage.threshold_reached']);
        $dispatcher = $fixture->dispatcher();

        $dispatcher->dispatch($this->thresholdEvent(limit: 100));
        $dispatcher->dispatch($this->thresholdEvent(limit: 150));

        self::assertSame($fixture->sent[0]->idempotencyKey, $fixture->sent[1]->idempotencyKey);
    }

    /**
     * The threshold event, with the limit in force when it was crossed.
     */
    private function thresholdEvent(int $limit): DomainEvent
    {
        return new DomainEvent(
            id: 'event-1',
            projectId: 'project-1',
            type: 'usage.threshold_reached',
            payload: [
                'customer_ref' => 'client-1',
                'meter' => 'messages',
                'used' => 80,
                'limit' => $limit,
                'threshold_bps' => 8000,
                'window_start' => '2026-11-01T00:00:00+00:00',
                'window_end' => '2026-11-30T23:59:59+00:00',
            ],
            correlationId: null,
            occurredAt: new DateTimeImmutable('2026-11-01T00:00:00+00:00'),
        );
    }

    /**
     * The usage event, with the counter and the limit the customer
     * reached.
     *
     * @param array<string, mixed> $payload
     */
    private function usageEvent(
        string $meter = 'messages',
        int $used = 100,
        int $limit = 100,
        array $payload = [],
    ): DomainEvent {
        return new DomainEvent(
            id: 'event-1',
            projectId: 'project-1',
            type: 'usage.limit_reached',
            payload: $payload + [
                'customer_ref' => 'client-1',
                'meter' => $meter,
                'used' => $used,
                'limit' => $limit,
                'threshold_bps' => 8000,
                'window_start' => '2026-11-01T00:00:00+00:00',
                'window_end' => '2026-11-30T23:59:59+00:00',
            ],
            correlationId: null,
            occurredAt: new DateTimeImmutable('2026-11-01T00:00:00+00:00'),
        );
    }
}
