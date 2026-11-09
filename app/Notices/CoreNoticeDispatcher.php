<?php

declare(strict_types=1);

namespace App\Notices;

use Core\Billing\Contracts\GetCustomer;
use Core\Billing\Contracts\GetCustomerCommand;
use Core\Events\Contracts\DomainEvent;
use Core\Identity\Contracts\GetProject;
use Core\Identity\Contracts\GetProjectCommand;
use Core\Notification\Contracts\ManageSystemNotificationSettings;
use Core\Notification\Contracts\NotificationChannel;
use Core\Notification\Contracts\SendSystemNotification;
use Core\Notification\Contracts\SendSystemNotificationCommand;
use Core\Support\Access\AccessScope;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * ADR-0118 §3 — the composition of the Core's notices.
 *
 * It lives in the application shell, the composition root: it reads what it
 * needs through the modules' `Contracts/` (the customer's phone from
 * `billing`, the project's name from `identity`, the declarations from
 * `notification`) and it hands the text to the `notification` module, which
 * owns the sending, the journal and the idempotence.
 *
 * The rules it applies:
 *
 *  - only the events of the catalogue are notifiable (ADR-0118 §3);
 *  - only the events the project declared are sent (ADR-0118 §4);
 *  - a customer without a known phone number is not written to (ADR-0118 §7);
 *  - one notice per event, per customer, per window (ADR-0118 §6).
 */
final readonly class CoreNoticeDispatcher
{
    public function __construct(
        private ManageSystemNotificationSettings $settings,
        private GetCustomer $customers,
        private GetProject $projects,
        private SendSystemNotification $sender,
        private CoreNoticeRenderer $renderer,
        private LoggerInterface $logger,
    ) {}

    public function dispatch(DomainEvent $event): void
    {
        $eventType = $event->type;

        if (! CoreNoticeCatalogue::isNotifiable($eventType)) {
            return;
        }

        $channel = CoreNoticeCatalogue::CHANNEL;
        $scope = AccessScope::forProject($event->projectId);

        $settings = $this->settings->get($event->projectId, $scope);
        if ($settings === null || ! $settings->wants($eventType, $channel)) {
            // Not declared: the project did not ask for it. Nothing is
            // sent, and nothing is said about it.
            return;
        }

        $customerRef = $this->customerRef($event->payload);
        if ($customerRef === null) {
            $this->skip($event, 'customer-ref-missing');

            return;
        }

        $customer = $this->customers->get(new GetCustomerCommand($event->projectId, $customerRef), $scope);
        if ($customer === null) {
            $this->skip($event, 'customer-not-found');

            return;
        }

        $phone = $customer->phone === null ? '' : trim($customer->phone);
        if ($phone === '') {
            $this->skip($event, 'customer-phone-unknown');

            return;
        }

        $text = $this->renderer->render(
            $eventType,
            $event->payload,
            $this->projectName($event->projectId, $scope),
            $customer->displayName,
        );

        if ($text === null) {
            $this->skip($event, 'no-message-for-event');

            return;
        }

        $result = $this->sender->send(new SendSystemNotificationCommand(
            projectId: $event->projectId,
            channel: NotificationChannel::fromString($channel),
            // A correlation reference, never an address (ADR-0041 §5): the
            // real destination is the phone number.
            toPartyRef: 'core-notice:'.$customerRef,
            text: $text,
            phoneNumber: $phone,
            idempotencyKey: $this->idempotencyKey($eventType, $event->payload, $customerRef),
            variables: ['customer_ref' => $customerRef],
        ));

        if (! $result->wasWritten()) {
            $this->skip($event, $result->reason ?? 'not-sent');
        }
    }

    /**
     * The key that makes a notice a replay instead of a second message
     * (ADR-0118 §6): one notice per event, per customer, per window.
     *
     * @param array<string, mixed> $payload
     */
    private function idempotencyKey(string $eventType, array $payload, string $customerRef): string
    {
        return 'core-notice:'.$eventType.':'.$customerRef.':'.CoreNoticeCatalogue::windowFor($eventType, $payload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function customerRef(array $payload): ?string
    {
        $ref = $payload['customer_ref'] ?? null;

        return is_string($ref) && $ref !== '' ? $ref : null;
    }

    private function projectName(string $projectId, AccessScope $scope): string
    {
        try {
            return $this->projects->get(new GetProjectCommand($projectId), $scope)->name;
        } catch (Throwable) {
            // A project that cannot be read is not a reason to skip the
            // notice: the message simply does not name it.
            return '';
        }
    }

    private function skip(DomainEvent $event, string $reason): void
    {
        $this->logger->info('core_notice.skipped', [
            'project_id' => $event->projectId,
            'event_type' => $event->type,
            'event_id' => $event->id,
            'reason' => $reason,
        ]);
    }
}
