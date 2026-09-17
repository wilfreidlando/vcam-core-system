<?php

declare(strict_types=1);

namespace Core\Notification\Application\Support;

use Core\Events\Contracts\DomainEvent;
use Core\Events\Contracts\EventOutbox;
use Core\Notification\Contracts\NotificationChannel;
use Core\Notification\Contracts\NotificationChannelProviderRegistry;
use Core\Notification\Contracts\NotificationProviderRouter;
use Core\Notification\Contracts\NotificationView;
use Core\Notification\Contracts\OutboundMessage;
use Core\Notification\Domain\Exception\NotificationAlreadyExists;
use Core\Notification\Domain\Model\Notification;
use Core\Notification\Domain\Port\NotificationDeliveryRepository;
use Core\Notification\Domain\Port\NotificationRepository;
use Core\Notification\NotificationStatus;
use Core\Parties\Contracts\FindOrCreatePartyCommand;
use Core\Parties\Contracts\PartyFinder;
use Core\Support\Clock;
use Core\Support\Identifier;
use Core\Support\UnitOfWork;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The provider-fallback cascade shared by `SendTextNotificationUseCase`,
 * `SendImageNotificationUseCase` and `SendFileNotificationUseCase`
 * (ADR-0043 — replaces the single template-based `SendNotificationUseCase`
 * from ADR-0041, one thin use case per content type instead of one
 * endpoint keyed by a pre-registered template). Not itself a `Contracts`
 * port — it's Application-internal orchestration reused by three
 * different public entry points.
 *
 * Tries each provider `NotificationProviderRouter` returns, in order,
 * until one accepts the message — a synchronous fallback cascade
 * (ADR-0041 §3), not a delayed retry (that's Webhook's job at Lot 4).
 *
 * The success path is deliberately NOT wrapped in
 * `UnitOfWork::transactional()` — mirrors `StartPaymentUseCase`: the only
 * DB write (`create()`) is already self-transactional, and the outbound
 * calls to providers happen outside any DB transaction so a slow or
 * hanging adapter never holds a lock. The all-providers-failed path DOES
 * use a transaction: unlike a successful send (no outbox event —
 * `queued`/`sent` are not in the canonical event catalogue), a definitive
 * failure publishes `notification.failed` right here, since no future
 * webhook will ever arrive to do it — and that write must share a
 * transaction with the state change (G8c).
 */
final readonly class NotificationSendCascade
{
    public function __construct(
        private NotificationRepository $notifications,
        private NotificationDeliveryRepository $deliveries,
        private NotificationProviderRouter $router,
        private NotificationChannelProviderRegistry $registry,
        private PartyFinder $partyFinder,
        private UnitOfWork $unitOfWork,
        private EventOutbox $outbox,
        private Identifier $identifier,
        private Clock $clock,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param array<string, mixed> $variables
     * @param array<string, mixed> $body
     */
    public function send(
        string $projectId,
        NotificationChannel $channel,
        string $toPartyRef,
        array $variables,
        array $body,
        string $contentType,
        string $idempotencyKey,
    ): NotificationView {
        $existing = $this->notifications->findByIdempotencyKey($projectId, $idempotencyKey);
        if ($existing !== null) {
            return $this->toView($existing);
        }

        // Correlation anchor only (ADR-0041 §5) — never a contact address.
        $this->partyFinder->findOrCreate(new FindOrCreatePartyCommand(
            $projectId,
            $toPartyRef,
            'notification_recipient',
        ));

        $id = $this->identifier->generate();

        // Created up front, in `queued`, before any provider is called —
        // not just at the end — so `notification_deliveries` rows (one
        // per attempt, written as the cascade runs) always have a real
        // parent row to reference, and so a genuine idempotency-key race
        // is caught before ever calling a provider.
        $queued = new Notification(
            id: $id,
            projectId: $projectId,
            channel: $channel->value,
            toPartyRef: $toPartyRef,
            contentType: $contentType,
            idempotencyKey: $idempotencyKey,
            status: NotificationStatus::Queued,
            providerCode: null,
            providerMessageId: null,
            errorMessage: null,
        );

        try {
            $this->notifications->create($queued);
        } catch (NotificationAlreadyExists) {
            $winner = $this->notifications->findByIdempotencyKey($projectId, $idempotencyKey);
            if ($winner !== null) {
                return $this->toView($winner);
            }

            throw new NotificationAlreadyExists;
        }

        $providers = $this->router->resolveProviders($channel, $projectId);

        $lastError = null;
        $attempt = 0;

        foreach ($providers as $providerCode) {
            $attempt++;
            $gateway = $this->registry->gatewayFor($providerCode);

            $outbound = new OutboundMessage(
                appMessageRef: $id,
                channel: $channel,
                recipientRef: $toPartyRef,
                variables: $variables,
                body: $body,
            );

            try {
                $result = $gateway->send($outbound);
            } catch (Throwable $e) {
                $lastError = $e->getMessage();
                $this->deliveries->record($id, $providerCode->toString(), null, NotificationStatus::Failed, $attempt);
                $this->logger->warning('notification.send.provider_failed', [
                    'notification_id' => $id,
                    'provider_code' => $providerCode->toString(),
                    'attempt' => $attempt,
                    'error' => $lastError,
                ]);

                continue;
            }

            $this->deliveries->record($id, $providerCode->toString(), $result->providerMessageId, $result->status, $attempt);

            $sent = $queued
                ->withStatusChange($result->status, $result->errorMessage)
                ->withProviderAttempt($providerCode->toString(), $result->providerMessageId);

            $this->notifications->save($sent);

            return $this->toView($sent);
        }

        $failed = $queued->withStatusChange(NotificationStatus::Failed, $lastError ?? 'Every configured provider failed.');

        $this->unitOfWork->transactional(function () use ($failed): void {
            $this->notifications->save($failed);
            $this->outbox->append(new DomainEvent(
                $this->identifier->generate(),
                $failed->projectId,
                'notification.failed',
                [
                    'notification_id' => $failed->id,
                    'channel' => $failed->channel,
                    'reason' => $failed->errorMessage,
                ],
                null,
                $this->clock->now(),
            ));
        });

        return $this->toView($failed);
    }

    private function toView(Notification $notification): NotificationView
    {
        return new NotificationView(
            id: $notification->id,
            channel: $notification->channel,
            status: $notification->status->value,
            contentType: $notification->contentType,
            errorMessage: $notification->errorMessage,
        );
    }
}
