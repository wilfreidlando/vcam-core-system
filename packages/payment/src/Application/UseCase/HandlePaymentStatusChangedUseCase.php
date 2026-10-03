<?php

declare(strict_types=1);

namespace Core\Payment\Application\UseCase;

use Core\Events\Contracts\DomainEvent;
use Core\Events\Contracts\EventOutbox;
use Core\Parties\Contracts\FindOrCreatePartyCommand;
use Core\Parties\Contracts\PartyFinder;
use Core\Payment\Application\Service\PaymentJourney;
use Core\Payment\Contracts\Events\PaymentStatusChanged;
use Core\Payment\Contracts\Events\PaymentStatusNotification;
use Core\Payment\Contracts\PaymentStatusChangeHandler;
use Core\Payment\Domain\Model\Payment;
use Core\Payment\Domain\Model\PaymentTimelineEntryType;
use Core\Payment\Domain\Port\PaymentRepository;
use Core\Payment\PaymentStatus;
use Core\Support\Clock;
use Core\Support\Identifier;
use Core\Support\UnitOfWork;
use Core\Wallet\Contracts\CreditWallet;
use Core\Wallet\Contracts\CreditWalletCommand;
use DateTimeZone;
use LogicException;
use Psr\Log\LoggerInterface;

/**
 * Applies a provider-reported status change to the Payment it belongs
 * to, and — only when the caller asked for it at initiation (ADR-0037)
 * AND the payment just settled — credits the wallet synchronously in the
 * same transaction (ADR-0036), before publishing the canonical event to
 * the outbox.
 *
 * `Payment` is resolved by `(providerCode, transactionRef)` — pinned at
 * `StartPaymentUseCase` time, never searched across every registered
 * adapter (ADR-0038 §4/§5).
 */
final readonly class HandlePaymentStatusChangedUseCase implements PaymentStatusChangeHandler
{
    public function __construct(
        private UnitOfWork $unitOfWork,
        private PaymentRepository $payments,
        private PartyFinder $partyFinder,
        private CreditWallet $creditWallet,
        private EventOutbox $outbox,
        private Identifier $identifier,
        private Clock $clock,
        private LoggerInterface $logger,
        private PaymentJourney $journey,
    ) {}

    public function handle(PaymentStatusChanged $event, string $source = 'provider_callback'): void
    {
        $this->unitOfWork->transactional(function () use ($event, $source): void {
            // Locked read: this handler is reached from two concurrent
            // entry points (inbound webhook, reconciliation job —
            // ADR-0044) racing on the same Payment row. The lock forces a
            // second concurrent call to block until the first commits,
            // then re-read the now-committed row, so its terminal-status
            // guard below correctly short-circuits instead of both calls
            // writing a stale transition.
            $payment = $this->payments->findByProviderTransactionRefForUpdate($event->providerCode, $event->transactionRef);

            if ($payment === null) {
                // A webhook for a transaction this Core never initiated
                // (replay drift, wrong environment, a payout webhook that
                // slipped past the controller's own filter). Nothing to
                // update — logged so an operator can spot a real pattern
                // versus one-off noise.
                $this->logger->warning('payment.status_changed.unknown_transaction', [
                    'provider_code' => $event->providerCode,
                    'transaction_ref' => $event->transactionRef,
                ]);

                return;
            }

            if (! $payment->status->canTransitionTo($event->status)) {
                // Idempotent: a replayed or duplicate webhook after the
                // payment already settled is a no-op, not an error.
                $this->logger->info('payment.status_changed.transition_refused', [
                    'payment_id' => $payment->id,
                    'status' => $payment->status->value,
                    'reported_status' => $event->status->value,
                ]);
                $this->journey->record(
                    $payment->id,
                    $payment->projectId,
                    PaymentTimelineEntryType::StatusChangeIgnored,
                    $event->status === $payment->status
                        ? sprintf('Statut « %s » reçu à nouveau (%s) : déjà appliqué, rien à faire.', $event->status->value, self::sourceLabel($source))
                        : sprintf('Transition %s → %s refusée (%s) : %s', $payment->status->value, $event->status->value, self::sourceLabel($source), $payment->status->isFinal() ? 'le paiement est déjà final.' : 'transition non autorisée.'),
                    ['source' => $source, 'current_status' => $payment->status->value, 'reported_status' => $event->status->value, 'reason' => $event->reason],
                );

                return;
            }

            $updated = $payment->withStatusChange($event->status, $event->reason);
            $this->payments->save($updated);

            $this->journey->record(
                $payment->id,
                $payment->projectId,
                PaymentTimelineEntryType::StatusChanged,
                sprintf('Statut %s → %s (%s)%s', $payment->status->value, $updated->status->value, self::sourceLabel($source), $updated->status->isFinal() ? ' — statut final' : ' — statut intermédiaire'),
                [
                    'source' => $source,
                    'from' => $payment->status->value,
                    'to' => $updated->status->value,
                    'is_final' => $updated->status->isFinal(),
                    'reason' => $event->reason,
                    'provider_reported_at' => $event->occurredAt,
                ],
            );

            if ($updated->shouldCreditWallet()) {
                if ($updated->isSandbox()) {
                    // ADR-0060: sandbox never moves real money — the
                    // instruction is acknowledged, the ledger untouched.
                    $this->journey->record(
                        $payment->id,
                        $payment->projectId,
                        PaymentTimelineEntryType::WalletCredited,
                        sprintf('SANDBOX — crédit de %d %s du wallet « %s » simulé (aucune écriture ledger)', $updated->amount, $updated->currency, (string) $updated->walletRecipientExternalRef),
                        ['simulated' => true, 'wallet_recipient_external_ref' => $updated->walletRecipientExternalRef, 'amount' => $updated->amount, 'currency' => $updated->currency],
                    );
                } else {
                    $this->creditWalletFor($updated);
                }
            }

            $eventId = $this->identifier->generate();
            $notification = new PaymentStatusNotification(
                paymentId: $updated->id,
                reference: $updated->idempotencyKey,
                status: $updated->status->value,
                isFinal: $updated->status->isFinal(),
                amount: $updated->amount,
                currency: $updated->currency,
                failureReason: $updated->status === PaymentStatus::Succeeded ? null : $updated->errorMessage,
                occurredAt: $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            );
            $this->outbox->append(new DomainEvent(
                $eventId,
                $updated->projectId,
                'payment.status_changed',
                // ADR-0061: the public, canonical shape — identical for
                // webhook endpoints and the payment's callback_url.
                $notification->toPayload(),
                null,
                $this->clock->now(),
                // User-directed design: when the caller supplied its own
                // callback_url at initiation, that's the one channel this
                // status change notifies on — not also every webhook
                // endpoint registered for the Project. See
                // `DomainEvent::$skipWebhookEndpointFanOut`'s docblock.
                skipWebhookEndpointFanOut: $updated->callbackUrl !== null,
                environment: $updated->environment->value,
            ));

            $this->journey->record(
                $payment->id,
                $payment->projectId,
                PaymentTimelineEntryType::EventPublished,
                $updated->callbackUrl !== null
                    ? 'Événement payment.status_changed publié — notifié sur le callback_url du paiement'
                    : 'Événement payment.status_changed publié — notifié aux endpoints webhook du projet',
                [
                    'event_id' => $eventId,
                    'event_type' => 'payment.status_changed',
                    'delivery' => $updated->callbackUrl !== null ? 'callback_url' : 'webhook_endpoints',
                    'payload' => $notification->toPayload(),
                ],
            );
        });
    }

    private static function sourceLabel(string $source): string
    {
        return match ($source) {
            'provider_callback' => 'callback du provider',
            'reconciliation' => 'réconciliation',
            'sandbox_simulation' => 'simulation sandbox',
            default => $source,
        };
    }

    private function creditWalletFor(Payment $payment): void
    {
        $externalRef = $payment->walletRecipientExternalRef
            ?? throw new LogicException('shouldCreditWallet() was true but walletRecipientExternalRef is null.');

        $party = $this->partyFinder->findOrCreate(new FindOrCreatePartyCommand(
            $payment->projectId,
            $externalRef,
            'payment_recipient',
        ));

        $balance = $this->creditWallet->credit(new CreditWalletCommand(
            $payment->projectId,
            $party->id,
            $payment->amount,
            $payment->currency,
            $payment->id,
            // One credit per payment, ever — a payment's own id is
            // naturally unique and never reused, so it doubles as the
            // ledger-level idempotency key (ADR-0033 §2).
            $payment->id,
        ));

        $this->journey->record(
            $payment->id,
            $payment->projectId,
            PaymentTimelineEntryType::WalletCredited,
            sprintf('Wallet de « %s » crédité de %d %s', $externalRef, $payment->amount, $payment->currency),
            [
                'wallet_recipient_external_ref' => $externalRef,
                'party_id' => $party->id,
                'wallet_id' => $balance->walletId,
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'balance_after_minor' => $balance->availableMinor,
            ],
        );
    }
}
