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
use Core\Payment\Domain\Model\MoneyException;
use Core\Payment\Domain\Model\MoneyExceptionKind;
use Core\Payment\Domain\Model\MoneyExceptionSeverity;
use Core\Payment\Domain\Model\Payment;
use Core\Payment\Domain\Model\PaymentTimelineEntryType;
use Core\Payment\Domain\Model\SettlementException;
use Core\Payment\Domain\Port\MoneyExceptionRepository;
use Core\Payment\Domain\Port\PaymentRepository;
use Core\Payment\Domain\Port\SettlementLedger;
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
 * to and, when the payment just settled, books the settlement in the same
 * transaction (ADR-0036, ADR-0076 §3), before publishing the canonical
 * event to the outbox. Exactly one of three bookings happens:
 *
 * - the provider confirmed another amount or currency (ADR-0077): the
 *   confirmed amount goes to `payment_exceptions`, nothing is credited;
 * - the caller asked for a wallet credit at initiation (ADR-0037): the
 *   wallet is credited (`clearing → wallet`);
 * - otherwise, a direct collection: `clearing → revenue`.
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
        private SettlementLedger $settlementLedger,
        private MoneyExceptionRepository $moneyExceptions,
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

            if ($this->isLateConfirmation($payment, $event)) {
                $this->applyLateConfirmation($payment, $event, $source);

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
            if ($updated->status === PaymentStatus::Succeeded) {
                $updated = $updated->withSettlement(
                    $event->confirmedAmountMinor,
                    $event->confirmedCurrency,
                    $payment->settlementExceptionFor($event->confirmedAmountMinor, $event->confirmedCurrency),
                );
            }
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

            if ($updated->status === PaymentStatus::Succeeded) {
                $this->bookSettlement($updated);
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
                confirmedAmount: $updated->confirmedAmount,
                exception: $updated->settlementException?->value,
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
            'simulation' => 'simulation',
            default => $source,
        };
    }

    /**
     * A payment settled by a source that confirms no amount (a
     * `checkStatus()` reconciliation) and the provider's own callback,
     * carrying the confirmed amount, arriving afterwards. The status does
     * not change, but the amount must still be checked (ADR-0077 §1).
     */
    private function isLateConfirmation(Payment $payment, PaymentStatusChanged $event): bool
    {
        return $payment->status === PaymentStatus::Succeeded
            && $event->status === PaymentStatus::Succeeded
            && $payment->confirmedAmount === null
            && $event->confirmedAmountMinor !== null;
    }

    /**
     * Records the late confirmation. The settlement was already booked on
     * the requested amount (revenue or a wallet credit), so nothing moves
     * here: a mismatch opens a CRITICAL exception with nothing booked, for
     * a person to correct — the money may already sit in a wallet.
     */
    private function applyLateConfirmation(Payment $payment, PaymentStatusChanged $event, string $source): void
    {
        $confirmedAmount = $event->confirmedAmountMinor ?? $payment->amount;
        $confirmedCurrency = $event->confirmedCurrency ?? $payment->currency;
        $exception = $payment->settlementExceptionFor($confirmedAmount, $confirmedCurrency);
        $updated = $payment->withSettlement($confirmedAmount, $confirmedCurrency, $exception);
        $this->payments->save($updated);

        if ($exception === null) {
            $this->journey->record(
                $payment->id,
                $payment->projectId,
                PaymentTimelineEntryType::SettlementRecorded,
                sprintf('Montant confirmé après coup par le prestataire (%s) : %d %s, conforme.', self::sourceLabel($source), $confirmedAmount, strtoupper($confirmedCurrency)),
                ['source' => $source, 'confirmed_amount' => $confirmedAmount, 'confirmed_currency' => strtoupper($confirmedCurrency), 'late' => true],
            );

            return;
        }

        $this->moneyExceptions->create(MoneyException::open(
            id: $this->identifier->generate(),
            projectId: $payment->projectId,
            kind: MoneyExceptionKind::fromSettlement($exception),
            severity: MoneyExceptionSeverity::Critical,
            amount: $confirmedAmount,
            currency: $confirmedCurrency,
            bookedAmount: 0,
            paymentId: $payment->id,
            subjectRef: $payment->walletRecipientExternalRef,
            summary: sprintf(
                'Paiement %s réglé sur %d %s avant confirmation ; le prestataire confirme ensuite %d %s. À corriger : la somme a déjà été écrite sur le montant demandé.',
                $payment->idempotencyKey,
                $payment->amount,
                $payment->currency,
                $confirmedAmount,
                strtoupper($confirmedCurrency),
            ),
            details: [
                'requested_amount' => $payment->amount,
                'requested_currency' => $payment->currency,
                'provider_code' => $payment->providerCode,
                'provider_transaction_ref' => $payment->providerTransactionRef,
                'wallet_recipient_external_ref' => $payment->walletRecipientExternalRef,
                'booked' => false,
                'late_confirmation' => true,
            ],
            now: $this->clock->now(),
        ));

        $this->logger->critical('payment.settlement.late_mismatch', [
            'payment_id' => $payment->id,
            'project_id' => $payment->projectId,
            'exception' => $exception->value,
            'requested' => [$payment->amount, $payment->currency],
            'confirmed' => [$confirmedAmount, strtoupper($confirmedCurrency)],
        ]);
        $this->journey->record(
            $payment->id,
            $payment->projectId,
            PaymentTimelineEntryType::SettlementException,
            sprintf('Confirmation tardive en écart : réglé sur %d %s, le prestataire confirme %d %s — exception critique ouverte.', $payment->amount, $payment->currency, $confirmedAmount, strtoupper($confirmedCurrency)),
            ['source' => $source, 'exception' => $exception->value, 'confirmed_amount' => $confirmedAmount, 'confirmed_currency' => strtoupper($confirmedCurrency), 'late' => true],
        );
    }

    /**
     * ADR-0076 §3 / ADR-0077 §2 — the one settlement booking of a payment
     * that just succeeded.
     */
    private function bookSettlement(Payment $payment): void
    {
        if ($payment->settlementException !== null) {
            $confirmedAmount = $payment->confirmedAmount ?? $payment->amount;
            $confirmedCurrency = $payment->confirmedCurrency ?? $payment->currency;
            $booked = $this->settlementLedger->recordUnallocatedSettlement($payment, $confirmedAmount, $confirmedCurrency);

            // ADR-0077 §3 — the exception a person will settle, opened in
            // the same transaction as the booking it accounts for.
            $this->moneyExceptions->create(MoneyException::open(
                id: $this->identifier->generate(),
                projectId: $payment->projectId,
                kind: MoneyExceptionKind::fromSettlement($payment->settlementException),
                severity: MoneyExceptionSeverity::High,
                amount: $confirmedAmount,
                currency: $confirmedCurrency,
                bookedAmount: $booked ? $confirmedAmount : 0,
                paymentId: $payment->id,
                subjectRef: $payment->walletRecipientExternalRef,
                summary: sprintf(
                    'Paiement %s : demandé %d %s, le prestataire confirme %d %s.',
                    $payment->idempotencyKey,
                    $payment->amount,
                    $payment->currency,
                    $confirmedAmount,
                    $confirmedCurrency,
                ),
                details: [
                    'requested_amount' => $payment->amount,
                    'requested_currency' => $payment->currency,
                    'provider_code' => $payment->providerCode,
                    'provider_transaction_ref' => $payment->providerTransactionRef,
                    'wallet_recipient_external_ref' => $payment->walletRecipientExternalRef,
                    'booked' => $booked,
                ],
                now: $this->clock->now(),
            ));

            $this->logger->warning('payment.settlement.exception', [
                'payment_id' => $payment->id,
                'project_id' => $payment->projectId,
                'exception' => $payment->settlementException->value,
                'requested' => [$payment->amount, $payment->currency],
                'confirmed' => [$confirmedAmount, $confirmedCurrency],
                'booked' => $booked,
            ]);
            $this->journey->record(
                $payment->id,
                $payment->projectId,
                PaymentTimelineEntryType::SettlementException,
                sprintf(
                    '%s : demandé %d %s, confirmé %d %s — %s',
                    $payment->settlementException === SettlementException::CurrencyMismatch ? 'Écart de devise' : 'Écart de montant',
                    $payment->amount,
                    $payment->currency,
                    $confirmedAmount,
                    $confirmedCurrency,
                    $booked ? 'mis en attente au compte des exceptions, rien n\'est crédité' : 'devise non prise en charge, rien n\'est écrit ni crédité',
                ),
                [
                    'exception' => $payment->settlementException->value,
                    'requested_amount' => $payment->amount,
                    'requested_currency' => $payment->currency,
                    'confirmed_amount' => $confirmedAmount,
                    'confirmed_currency' => $confirmedCurrency,
                    'booked' => $booked,
                ],
            );

            return;
        }

        if ($payment->shouldCreditWallet()) {
            $this->creditWalletFor($payment);

            return;
        }

        $this->settlementLedger->recordDirectSettlement($payment);
        $this->journey->record(
            $payment->id,
            $payment->projectId,
            PaymentTimelineEntryType::SettlementRecorded,
            sprintf(
                'Encaissement de %d %s écrit au grand livre (revenu du projet)%s',
                $payment->amount,
                $payment->currency,
                $payment->confirmedAmount === null ? ' — montant non confirmé par la source' : '',
            ),
            [
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'amount_verified' => $payment->confirmedAmount !== null,
            ],
        );
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
            $externalRef,
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
