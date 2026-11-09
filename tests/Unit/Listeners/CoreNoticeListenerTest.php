<?php

declare(strict_types=1);

namespace Tests\Unit\Listeners;

use App\Listeners\CoreNoticeListener;
use Core\Events\Contracts\OutboxEventPublished;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Support\CoreNoticeFixture;

/**
 * ADR-0118 — the Core's notices, on the events the relay republishes.
 */
final class CoreNoticeListenerTest extends TestCase
{
    #[Test]
    public function a_relayed_quota_limit_sends_one_notice_to_the_customer(): void
    {
        $fixture = new CoreNoticeFixture(['usage.limit_reached']);
        $listener = new CoreNoticeListener($fixture->dispatcher(), new NullLogger);

        $listener->handle(new OutboxEventPublished(CoreNoticeFixture::usageLimitReached()));

        self::assertCount(1, $fixture->sent);
        self::assertSame('+237690000000', $fixture->sent[0]->phoneNumber);
    }

    #[Test]
    public function a_relay_the_project_did_not_ask_for_sends_nothing(): void
    {
        $fixture = new CoreNoticeFixture([]);
        $listener = new CoreNoticeListener($fixture->dispatcher(), new NullLogger);

        $listener->handle(new OutboxEventPublished(CoreNoticeFixture::usageLimitReached()));

        self::assertCount(0, $fixture->sent);
    }

    #[Test]
    public function a_notice_that_cannot_be_sent_never_fails_the_relay(): void
    {
        $fixture = new CoreNoticeFixture(['usage.limit_reached']);
        $fixture->failSending = true;
        $listener = new CoreNoticeListener($fixture->dispatcher(), new NullLogger);

        // The event is already in the outbox: the relay must not fail.
        $listener->handle(new OutboxEventPublished(CoreNoticeFixture::usageLimitReached()));

        self::assertCount(1, $fixture->sent);
    }
}
