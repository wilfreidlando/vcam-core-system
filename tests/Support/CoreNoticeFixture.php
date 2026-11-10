<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Notices\CoreNoticeDispatcher;
use App\Notices\CoreNoticeRenderer;
use Core\Billing\Contracts\CustomerView;
use Core\Billing\Contracts\GetCustomer;
use Core\Billing\Contracts\GetCustomerCommand;
use Core\Events\Contracts\DomainEvent;
use Core\Identity\Contracts\GetProject;
use Core\Identity\Contracts\GetProjectCommand;
use Core\Identity\Contracts\ProjectView;
use Core\Notification\Contracts\ManageSystemNotificationSettings;
use Core\Notification\Contracts\NotificationView;
use Core\Notification\Contracts\SaveSystemNotificationSettingsCommand;
use Core\Notification\Contracts\SendSystemNotification;
use Core\Notification\Contracts\SendSystemNotificationCommand;
use Core\Notification\Contracts\SystemNotificationPreferenceView;
use Core\Notification\Contracts\SystemNotificationResult;
use Core\Notification\Contracts\SystemNotificationSettingsView;
use Core\Support\Access\AccessScope;
use DateTimeImmutable;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * ADR-0118 — the Core's notices, assembled without a database: the
 * declarations, the customer and the project are read by fakes, and the
 * sends are recorded for the test to read.
 */
final class CoreNoticeFixture
{
    /** @var list<SendSystemNotificationCommand> */
    public array $sent = [];

    /**
     * Whether the sender blows up — a notice that cannot be sent must
     * never fail the relay (ADR-0118 §7).
     */
    public bool $failSending = false;

    /**
     * @param list<string> $declared the events the project declared
     */
    public function __construct(
        private readonly array $declared = [],
        private readonly ?string $phone = '+237690000000',
        private readonly string $customerName = 'Awa',
        private readonly string $projectName = 'Auto-école',
    ) {}

    public function dispatcher(): CoreNoticeDispatcher
    {
        $declared = $this->declared;
        $phone = $this->phone;
        $customerName = $this->customerName;
        $projectName = $this->projectName;

        $settings = new class($declared) implements ManageSystemNotificationSettings
        {
            /**
             * @param list<string> $declared
             */
            public function __construct(private readonly array $declared) {}

            public function get(string $projectId, AccessScope $scope): ?SystemNotificationSettingsView
            {
                if (! $scope->allowsProject($projectId)) {
                    return null;
                }

                $preferences = [];
                foreach ($this->declared as $eventType) {
                    $preferences[] = new SystemNotificationPreferenceView($eventType, 'whatsapp');
                }

                return new SystemNotificationSettingsView($projectId, $preferences);
            }

            public function save(SaveSystemNotificationSettingsCommand $command, AccessScope $scope): ?SystemNotificationSettingsView
            {
                return $this->get($command->projectId, $scope);
            }
        };

        $customers = new class($phone, $customerName) implements GetCustomer
        {
            public function __construct(
                private readonly ?string $phone,
                private readonly string $name,
            ) {}

            public function get(GetCustomerCommand $command, AccessScope $scope): ?CustomerView
            {
                if (! $scope->allowsProject($command->projectId)) {
                    return null;
                }

                return new CustomerView(
                    id: 'customer-1',
                    projectId: $command->projectId,
                    externalRef: $command->externalRef,
                    displayName: $this->name,
                    email: null,
                    phone: $this->phone,
                    locale: 'fr',
                    metadata: [],
                    status: 'active',
                    version: 1,
                    createdAt: '2026-11-01T00:00:00+00:00',
                    updatedAt: '2026-11-01T00:00:00+00:00',
                );
            }
        };

        $projects = new class($projectName) implements GetProject
        {
            public function __construct(private readonly string $name) {}

            public function get(GetProjectCommand $command, AccessScope $scope): ProjectView
            {
                return new ProjectView($command->projectId, 'organization-1', $this->name, 'active');
            }
        };

        $sender = new class($this) implements SendSystemNotification
        {
            public function __construct(private readonly CoreNoticeFixture $fixture) {}

            public function send(SendSystemNotificationCommand $command): SystemNotificationResult
            {
                $this->fixture->sent[] = $command;

                if ($this->fixture->failSending) {
                    throw new RuntimeException('the provider is unreachable');
                }

                return SystemNotificationResult::of(new NotificationView(
                    id: 'notification-1',
                    channel: $command->channel->value,
                    status: 'sent',
                    contentType: 'text',
                    errorMessage: null,
                ));
            }
        };

        return new CoreNoticeDispatcher(
            $settings,
            $customers,
            $projects,
            $sender,
            new CoreNoticeRenderer,
            new NullLogger,
        );
    }

    /**
     * The event the commerce publishes when a customer reaches his quota
     * (ADR-0074 §2): no personal data, the customer is its own reference.
     */
    public static function usageLimitReached(string $windowEnd = '2026-11-30T23:59:59+00:00'): DomainEvent
    {
        return new DomainEvent(
            id: 'event-1',
            projectId: 'project-1',
            type: 'usage.limit_reached',
            payload: [
                'customer_ref' => 'client-1',
                'meter' => 'messages',
                'used' => 100,
                'limit' => 100,
                'threshold_bps' => 8000,
                'window_start' => '2026-11-01T00:00:00+00:00',
                'window_end' => $windowEnd,
            ],
            correlationId: null,
            occurredAt: new DateTimeImmutable('2026-11-01T00:00:00+00:00'),
        );
    }
}
