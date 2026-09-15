<?php

declare(strict_types=1);

namespace Core\Payment\Application\UseCase;

use Core\Events\Contracts\DomainEvent;
use Core\Events\Contracts\EventOutbox;
use Core\Parties\Contracts\FindOrCreatePartyCommand;
use Core\Parties\Contracts\PartyFinder;
use Core\Payment\Contracts\Events\PaymentStatusChanged;
use Core\Payment\Contracts\PaymentStatusChangeHandler;
use Core\Payment\Domain\Model\Payment;
use Core\Payment\Domain\Port\PaymentRepository;
use Core\Support\Clock;
use Core\Support\Identifier;
use Core\Support\UnitOfWork;
use Core\Wallet\Contracts\CreditWallet;
use Core\Wallet\Contracts\CreditWalletCommand;
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
    ) {}

    public function handle(PaymentStatusChanged $event): void
    {
        $this->unitOfWork->transactional(function () use ($event): void {
            $payment = $this->payments->findByProviderTransactionRef($event->providerCode, $event->transactionRef);

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

            if ($payment->status->isTerminal()) {
                // Idempotent: a replayed or duplicate webhook after the
                // payment already settled is a no-op, not an error.
                $this->logger->info('payment.status_changed.already_terminal', [
                    'payment_id' => $payment->id,
                    'status' => $payment->status->value,
                ]);

                return;
            }

            $updated = $payment->withStatusChange($event->status, $event->reason);
            $this->payments->save($updated);

            if ($updated->shouldCreditWallet()) {
                $this->creditWalletFor($updated);
            }

            $this->outbox->append(new DomainEvent(
                $this->identifier->generate(),
                $updated->projectId,
                'payment.status_changed',
                $event->toPayload(),
                null,
                $this->clock->now(),
            ));
        });
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

        $this->creditWallet->credit(new CreditWalletCommand(
            $payment->projectId,
            $party->id,
            $payment->amountMinorUnits,
            $payment->currency,
            $payment->id,
            // One credit per payment, ever — a payment's own id is
            // naturally unique and never reused, so it doubles as the
            // ledger-level idempotency key (ADR-0033 §2).
            $payment->id,
        ));
    }
}
