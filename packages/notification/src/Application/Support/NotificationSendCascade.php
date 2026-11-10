<?php

declare(strict_types=1);

namespace Core\Notification\Application\Support;

use Core\Events\Contracts\DomainEvent;
use Core\Events\Contracts\EventOutbox;
use Core\Notification\Contracts\ChannelSessionNotFound;
use Core\Notification\Contracts\ChannelSessionProvider;
use Core\Notification\Contracts\MissingChannelSession;
use Core\Notification\Contracts\NotificationChannel;
use Core\Notification\Contracts\NotificationChannelProviderRegistry;
use Core\Notification\Contracts\NotificationCustomerInvalid;
use Core\Notification\Contracts\NotificationNotEntitled;
use Core\Notification\Contracts\NotificationProviderRouter;
use Core\Notification\Contracts\NotificationQuotaExceeded;
use Core\Notification\Contracts\NotificationView;
use Core\Notification\Contracts\OutboundMessage;
use Core\Notification\Domain\Exception\NotificationAlreadyExists;
use Core\Notification\Domain\Model\ChannelSession;
use Core\Notification\Domain\Model\Notification;
use Core\Notification\Domain\Model\QuotaVerdict;
use Core\Notification\Domain\Port\ChannelSessionRepository;
use Core\Notification\Domain\Port\NotificationDeliveryRepository;
use Core\Notification\Domain\Port\NotificationRepository;
use Core\Notification\Domain\Port\QuotaGuard;
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
    /** The capability a project maps to a meter (`billing_settings.meter_mappings`, ADR-0074 §5). */
    private const QUOTA_CAPABILITY = 'notification.whatsapp';

    public function __construct(
        private NotificationRepository $notifications,
        private NotificationDeliveryRepository $deliveries,
        private NotificationProviderRouter $router,
        private NotificationChannelProviderRegistry $registry,
        private ChannelSessionRepository $sessions,
        private PartyFinder $partyFinder,
        private UnitOfWork $unitOfWork,
        private EventOutbox $outbox,
        private Identifier $identifier,
        private Clock $clock,
        private LoggerInterface $logger,
        private ?QuotaGuard $quota = null,
    ) {}

    /**
     * @param array<string, mixed> $variables
     * @param array<string, mixed> $body
     * @param ChannelSession|null $session a session the caller resolved
     *                                     itself — the Core's own platform session (ADR-0118 §2). When
     *                                     it is given, the session is **not** resolved again: a
     *                                     project's session of the same name never takes the platform's
     *                                     place. When it is null, the session named in `$variables` is
     *                                     resolved as usual — the Project's own registration first, else
     *                                     a global one (ADR-0114 §3).
     * @param bool $metered whether the send spends a unit of the
     *                      recipient's quota. The Core's own notices do not (ADR-0118 §5):
     *                      the Core informs a customer of their own commercial state,
     *                      which is not a service that customer's plan buys.
     */
    public function send(
        string $projectId,
        NotificationChannel $channel,
        string $toPartyRef,
        array $variables,
        array $body,
        string $contentType,
        string $idempotencyKey,
        ?ChannelSession $session = null,
        bool $metered = true,
    ): NotificationView {
        $existing = $this->notifications->findByIdempotencyKey($projectId, $idempotencyKey);
        if ($existing !== null) {
            return $this->toView($existing);
        }

        // After the idempotent replay above (an already-sent notification
        // must still be returned even if its session is gone later), before
        // anything is written: a session the client names that does not
        // is not registered for this Project is refused here with an
        // explicit error — no party, no notification row, no provider call.
        $variables = $session === null
            ? $this->withResolvedSession($channel, $projectId, $variables)
            : $this->withGivenSession($session, $variables);

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
            content: $body,
            variables: $variables,
        );

        // The notification and its unit of quota exist together or not at
        // all (ADR-0074 §5): a refusal rolls the `queued` row back, so a
        // refused notification leaves no row and calls no provider.
        $capability = self::QUOTA_CAPABILITY;
        $customerRef = $this->customerRef($variables);

        try {
            $this->unitOfWork->transactional(function () use ($queued, $projectId, $customerRef, $capability, $idempotencyKey, $metered): void {
                $this->notifications->create($queued);
                if ($metered) {
                    $this->spendQuota($projectId, $customerRef, $capability, $idempotencyKey);
                }
            });
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
                projectId: $projectId,
            );

            try {
                $result = $gateway->send($outbound);
            } catch (Throwable $e) {
                $lastError = $e->getMessage();
                $this->deliveries->record($id, $providerCode->toString(), null, NotificationStatus::Failed, $attempt, $lastError);
                $this->logger->warning('notification.send.provider_failed', [
                    'notification_id' => $id,
                    'provider_code' => $providerCode->toString(),
                    'attempt' => $attempt,
                    'error' => $lastError,
                ]);

                continue;
            }

            $this->deliveries->record($id, $providerCode->toString(), $result->providerMessageId, $result->status, $attempt, $result->errorMessage);

            $sent = $queued
                ->withStatusChange($result->status, $result->errorMessage)
                ->withProviderAttempt($providerCode->toString(), $result->providerMessageId);

            $this->notifications->save($sent);

            return $this->toView($sent);
        }

        $failed = $queued->withStatusChange(NotificationStatus::Failed, $lastError ?? 'Every configured provider failed.');

        $this->unitOfWork->transactional(function () use ($failed, $projectId, $customerRef, $capability, $idempotencyKey, $metered): void {
            $this->notifications->save($failed);
            // Nobody was reached: the unit goes back (ADR-0074 §5). Nothing
            // goes back when the send was not metered in the first place.
            if ($metered) {
                $this->quota?->release($projectId, $customerRef, $capability, $idempotencyKey);
            }
            $this->outbox->append(new DomainEvent(
                $this->identifier->generate(),
                $failed->projectId,
                'notification.failed',
                [
                    'notification_id' => $failed->id,
                    'channel' => $failed->channel,
                    // `status` was missing here — this is a second
                    // producer of `notification.failed` alongside
                    // `HandleNotificationStatusChangedUseCase`'s (a
                    // provider callback reporting failure later), whose
                    // payload always carries `status`. A webhook receiver
                    // that reasonably expected every `notification.failed`
                    // delivery to have the same shape, regardless of which
                    // path produced it, would have silently broken on this
                    // one (review finding — payload shape must be the same
                    // for the same event type, whichever producer emits
                    // it).
                    'status' => $failed->status->value,
                    'reason' => $failed->errorMessage,
                ],
                null,
                $this->clock->now(),
            ));
        });

        return $this->toView($failed);
    }

    /**
     * A session-based channel (WhatsApp) sends only from a session the
     * operator registered — for THIS Project, or a global one — checked
     * against the Core's own table, never by asking the provider. Returns
     * the variables with `session` replaced by the name the session has at
     * the provider (project-namespaced for a Project's own, plain for a
     * global one). Whether a phone is actually linked stays the provider's
     * business (an unlinked session simply fails at the provider).
     *
     * @param array<string, mixed> $variables
     * @return array<string, mixed>
     *
     * @throws MissingChannelSession
     * @throws ChannelSessionNotFound
     */
    private function withResolvedSession(NotificationChannel $channel, string $projectId, array $variables): array
    {
        $gateway = $this->registry->gatewayFor($this->router->resolveProviders($channel, $projectId)[0]);
        if (! $gateway instanceof ChannelSessionProvider) {
            return $variables;
        }

        $session = $variables['session'] ?? null;
        if (! is_string($session) || $session === '') {
            throw MissingChannelSession::forRequest();
        }

        $registered = $this->sessions->findUsableFor($projectId, $channel->value, $session)
            ?? throw new ChannelSessionNotFound($session);

        $variables['session'] = $registered->providerName();

        return $variables;
    }

    /**
     * A session the caller resolved itself — the Core's own platform
     * session (ADR-0118 §2). It is used as it was given: the name it has
     * at the provider, and no lookup against the Project, so a Project's
     * own session of the same name cannot answer for the platform.
     *
     * @param array<string, mixed> $variables
     * @return array<string, mixed>
     */
    private function withGivenSession(ChannelSession $session, array $variables): array
    {
        $variables['session'] = $session->providerName();

        return $variables;
    }

    /**
     * @param array<string, mixed> $variables
     */
    private function customerRef(array $variables): ?string
    {
        $ref = $variables['customer_ref'] ?? null;

        return is_string($ref) && $ref !== '' ? $ref : null;
    }

    private function spendQuota(string $projectId, ?string $customerRef, string $capability, string $idempotencyKey): void
    {
        if ($this->quota === null) {
            return;
        }

        $verdict = $this->quota->consume($projectId, $customerRef, $capability, $idempotencyKey);

        if ($verdict->isAllowed()) {
            return;
        }

        $detail = $verdict->detail ?? 'The notification was refused by the quota authority.';

        throw match ($verdict->outcome) {
            QuotaVerdict::EXCEEDED => new NotificationQuotaExceeded($detail),
            QuotaVerdict::NOT_ENTITLED => new NotificationNotEntitled($detail),
            default => new NotificationCustomerInvalid($detail),
        };
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
