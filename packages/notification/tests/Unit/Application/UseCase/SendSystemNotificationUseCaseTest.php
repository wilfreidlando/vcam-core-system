<?php

declare(strict_types=1);

namespace Core\Notification\Tests\Unit\Application\UseCase;

use Core\Notification\Application\Support\NotificationSendCascade;
use Core\Notification\Application\UseCase\SendSystemNotificationUseCase;
use Core\Notification\Contracts\NotificationChannel;
use Core\Notification\Contracts\SendSystemNotificationCommand;
use Core\Notification\Domain\Model\ChannelSession;
use Core\Notification\Domain\Model\QuotaVerdict;
use Core\Notification\Domain\Port\QuotaGuard;
use Core\Notification\Tests\Unit\Fakes\FakeNotificationChannelProvider;
use Core\Notification\Tests\Unit\Fakes\FakeNotificationChannelProviderRegistry;
use Core\Notification\Tests\Unit\Fakes\FakeNotificationProviderRouter;
use Core\Notification\Tests\Unit\Fakes\FakePartyFinder;
use Core\Notification\Tests\Unit\Fakes\FixedClock;
use Core\Notification\Tests\Unit\Fakes\InMemoryChannelSessionRepository;
use Core\Notification\Tests\Unit\Fakes\InMemoryEventOutbox;
use Core\Notification\Tests\Unit\Fakes\InMemoryNotificationDeliveryRepository;
use Core\Notification\Tests\Unit\Fakes\InMemoryNotificationRepository;
use Core\Notification\Tests\Unit\Fakes\PassthroughUnitOfWork;
use Core\Notification\Tests\Unit\Fakes\SequentialIdentifier;
use Core\Support\Integration\Provider\ProviderCode;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * ADR-0118 §2 — the Core's own voice: the platform's session, the
 * platform's number, and no quota spent on the recipient's plan.
 */
final class SendSystemNotificationUseCaseTest extends TestCase
{
    #[Test]
    public function the_message_leaves_from_the_platforms_global_session_and_not_from_the_projects_own(): void
    {
        $sessions = new InMemoryChannelSessionRepository;
        $sessions->save($this->session('session-global', null, 'default'));
        $sessions->save($this->session('session-project', 'project-1', 'default'));

        [$useCase, $gateway, $notifications] = $this->useCase($sessions);

        $result = $useCase->send($this->command());

        self::assertTrue($result->wasWritten());
        self::assertSame('sent', $result->status);
        // The global session's name at the provider is `default`; the
        // Project's own session of the same name would be `project-1_default`.
        self::assertSame('default', $gateway->sent[0]->variables['session']);
        self::assertSame(1, $notifications->count());
    }

    #[Test]
    public function nothing_is_written_when_the_platform_has_no_session(): void
    {
        $sessions = new InMemoryChannelSessionRepository;

        [$useCase, $gateway, $notifications] = $this->useCase($sessions);

        $result = $useCase->send($this->command());

        self::assertFalse($result->wasWritten());
        self::assertSame('platform-session-missing', $result->reason);
        self::assertCount(0, $gateway->sent);
        self::assertSame(0, $notifications->count());
    }

    #[Test]
    public function the_notice_of_the_core_is_not_metered_against_the_customers_plan(): void
    {
        $quota = new class implements QuotaGuard
        {
            /** @var list<string> */
            public array $calls = [];

            public function consume(string $projectId, ?string $customerRef, string $capability, string $idempotencyKey): QuotaVerdict
            {
                $this->calls[] = $capability;

                // A metered send would meet this refusal and leave no row
                // at all: the notification is rolled back with the quota.
                return QuotaVerdict::refused(QuotaVerdict::NOT_ENTITLED, 'the plan does not include messaging');
            }

            public function release(string $projectId, ?string $customerRef, string $capability, string $idempotencyKey): void {}
        };

        $sessions = new InMemoryChannelSessionRepository;
        $sessions->save($this->session('session-global', null, 'default'));

        [$useCase, $gateway] = $this->useCase($sessions, $quota);

        $result = $useCase->send($this->command());

        self::assertTrue($result->wasWritten());
        self::assertSame([], $quota->calls);
        self::assertCount(1, $gateway->sent);
    }

    #[Test]
    public function the_same_idempotency_key_sends_once_and_replays_the_first_notification(): void
    {
        $sessions = new InMemoryChannelSessionRepository;
        $sessions->save($this->session('session-global', null, 'default'));

        [$useCase, $gateway] = $this->useCase($sessions);

        $first = $useCase->send($this->command());
        $second = $useCase->send($this->command());

        self::assertSame($first->notificationId, $second->notificationId);
        self::assertCount(1, $gateway->sent);
    }

    /**
     * @return array{0: SendSystemNotificationUseCase, 1: FakeNotificationChannelProvider, 2: InMemoryNotificationRepository}
     */
    private function useCase(InMemoryChannelSessionRepository $sessions, ?QuotaGuard $quota = null): array
    {
        $notifications = new InMemoryNotificationRepository;
        $deliveries = new InMemoryNotificationDeliveryRepository;
        $registry = new FakeNotificationChannelProviderRegistry;
        $gateway = new FakeNotificationChannelProvider(ProviderCode::fromString('whatsapp-waha'));
        $registry->register($gateway);

        $cascade = new NotificationSendCascade(
            $notifications,
            $deliveries,
            new FakeNotificationProviderRouter(['whatsapp-waha']),
            $registry,
            $sessions,
            new FakePartyFinder,
            new PassthroughUnitOfWork,
            new InMemoryEventOutbox,
            new SequentialIdentifier,
            new FixedClock,
            new NullLogger,
            $quota,
        );

        return [
            new SendSystemNotificationUseCase($cascade, $sessions, new NullLogger, 'default'),
            $gateway,
            $notifications,
        ];
    }

    private function command(string $idempotencyKey = 'core-notice:usage.limit_reached:client-1:2026-12-31'): SendSystemNotificationCommand
    {
        return new SendSystemNotificationCommand(
            projectId: 'project-1',
            channel: NotificationChannel::fromString('whatsapp'),
            toPartyRef: 'core-notice:client-1',
            text: 'Bonjour Awa, votre quota est atteint.',
            phoneNumber: '+237690000000',
            idempotencyKey: $idempotencyKey,
            variables: ['customer_ref' => 'client-1'],
        );
    }

    private function session(string $id, ?string $projectId, string $ref): ChannelSession
    {
        return new ChannelSession(
            $id,
            $projectId,
            'whatsapp',
            $ref,
            new DateTimeImmutable('2026-11-01T00:00:00+00:00'),
        );
    }
}
