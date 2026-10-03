<?php

declare(strict_types=1);

namespace Core\Payment\Application\UseCase;

use Core\Payment\Application\Port\SandboxPaymentSimulator;
use Core\Payment\Application\Service\PaymentJourney;
use Core\Payment\Contracts\Events\PaymentStatusChanged;
use Core\Payment\Contracts\PaymentGatewayRegistry;
use Core\Payment\Contracts\PaymentStatusChangeHandler;
use Core\Payment\Contracts\ReconcileStuckPayments;
use Core\Payment\Contracts\ReconcileStuckPaymentsCommand;
use Core\Payment\Contracts\ReconciliationSummary;
use Core\Payment\Contracts\UnsupportedPaymentGatewayException;
use Core\Payment\Domain\Model\Payment;
use Core\Payment\Domain\Model\PaymentTimelineEntryType;
use Core\Payment\Domain\Port\PaymentRepository;
use Core\Support\Clock;
use Core\Support\Integration\CircuitBreaker\CircuitOpenException;
use Core\Support\Integration\Exception\ProviderException;
use Core\Support\Integration\Provider\ProviderCode;
use DateTimeZone;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Background reconciliation for missed webhooks (spec technique §10).
 * Converges on the exact same `PaymentStatusChangeHandler::handle()` the
 * inbound webhook path uses (`HandlePaymentStatusChangedUseCase`'s own
 * docblock anticipated this: "a future checkStatus()-based reconciliation
 * job... will call too, so both paths converge on identical, tested
 * behaviour") — so this use case adds no transactional/idempotency/outbox
 * logic of its own (G8 stays intact: no raw `dispatch()`/`event()` here).
 *
 * A failure on one payment — provider-side or not (a persistence error,
 * a domain invariant, anything `PaymentStatusChangeHandler` can throw) —
 * is logged and skipped. The batch must never abort partway through: with
 * `findStuckSince()` ordering the oldest-untouched payment first and no
 * separate starvation guard, a single payment whose processing always
 * throws would otherwise sit at the head of every future run forever,
 * silently blocking reconciliation for the whole platform (caught in
 * review — a narrower catch here previously let non-provider exceptions
 * escape and abort the loop).
 */
final readonly class ReconcileStuckPaymentsUseCase implements ReconcileStuckPayments
{
    public function __construct(
        private PaymentRepository $payments,
        private PaymentGatewayRegistry $gateways,
        private PaymentStatusChangeHandler $statusChangeHandler,
        private Clock $clock,
        private LoggerInterface $logger,
        private int $stuckAfterMinutes,
        private int $batchSize,
        private PaymentJourney $journey,
    ) {}

    public function reconcile(ReconcileStuckPaymentsCommand $command): ReconciliationSummary
    {
        $limit = $command->limitOverride ?? $this->batchSize;
        $threshold = $this->clock->now()->modify(sprintf('-%d minutes', $this->stuckAfterMinutes));

        $stuck = $this->payments->findStuckSince($threshold, $limit);

        $checked = 0;
        $updated = 0;
        $errors = 0;

        foreach ($stuck as $payment) {
            // ADR-0060: simulated sandbox payments have no provider to ask.
            if ($payment->providerCode === SandboxPaymentSimulator::PROVIDER_CODE) {
                continue;
            }

            $checked++;

            try {
                $changed = $this->reconcileOne($payment);
                if ($changed) {
                    $updated++;
                }
            } catch (ProviderException|CircuitOpenException|UnsupportedPaymentGatewayException $e) {
                $errors++;
                $this->logger->warning('payment.reconciliation.check_status_failed', [
                    'payment_id' => $payment->id,
                    'provider_code' => $payment->providerCode,
                    'error' => $e->getMessage(),
                ]);
            } catch (Throwable $e) {
                // Anything else — including a failure inside
                // PaymentStatusChangeHandler itself (persistence, a
                // domain invariant) — must not kill the batch either.
                // Logged at error level, distinct from the expected/
                // routine provider-side warning above.
                $errors++;
                $this->logger->error('payment.reconciliation.payment_failed', [
                    'payment_id' => $payment->id,
                    'provider_code' => $payment->providerCode,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return new ReconciliationSummary($checked, $updated, $errors);
    }

    private function reconcileOne(Payment $payment): bool
    {
        $gateway = $this->gateways->gatewayFor(ProviderCode::fromString($payment->providerCode));
        $this->journey->forgetPendingExchanges();
        $receipt = $gateway->checkStatus($payment->providerTransactionRef, $payment->projectId, $payment->environment);
        $this->journey->recordProviderExchanges($payment->id, $payment->projectId);
        $this->journey->record(
            $payment->id,
            $payment->projectId,
            PaymentTimelineEntryType::ReconciliationChecked,
            $receipt->status === $payment->status
                ? sprintf('Réconciliation : le provider confirme le statut « %s », rien à changer.', $payment->status->value)
                : sprintf('Réconciliation : le provider annonce « %s » (Core : « %s »).', $receipt->status->value, $payment->status->value),
            ['core_status' => $payment->status->value, 'provider_status' => $receipt->status->value, 'provider_message' => $receipt->errorMessage],
        );

        if ($receipt->status === $payment->status) {
            return false;
        }

        $occurredAt = $this->clock->now()
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');

        $this->statusChangeHandler->handle(new PaymentStatusChanged(
            providerCode: $payment->providerCode,
            transactionRef: $payment->providerTransactionRef,
            appTransactionRef: $payment->idempotencyKey,
            status: $receipt->status,
            reason: $receipt->errorMessage,
            occurredAt: $occurredAt,
        ), 'reconciliation');

        return true;
    }
}
