<?php

declare(strict_types=1);

namespace Core\Payment\Application\UseCase;

use Core\Payment\Contracts\Events\PaymentStatusChanged;
use Core\Payment\Contracts\PaymentGatewayRegistry;
use Core\Payment\Contracts\PaymentStatusChangeHandler;
use Core\Payment\Contracts\ReconcileStuckPayments;
use Core\Payment\Contracts\ReconcileStuckPaymentsCommand;
use Core\Payment\Contracts\ReconciliationSummary;
use Core\Payment\Contracts\UnsupportedPaymentGatewayException;
use Core\Payment\Domain\Model\Payment;
use Core\Payment\Domain\Port\PaymentRepository;
use Core\Support\Clock;
use Core\Support\Integration\CircuitBreaker\CircuitOpenException;
use Core\Support\Integration\Exception\ProviderException;
use Core\Support\Integration\Provider\ProviderCode;
use DateTimeZone;
use Psr\Log\LoggerInterface;

/**
 * Background reconciliation for missed webhooks (spec technique §10).
 * Converges on the exact same `PaymentStatusChangeHandler::handle()` the
 * inbound webhook path uses (`HandlePaymentStatusChangedUseCase`'s own
 * docblock anticipated this: "a future checkStatus()-based reconciliation
 * job... will call too, so both paths converge on identical, tested
 * behaviour") — so this use case adds no transactional/idempotency/outbox
 * logic of its own (G8 stays intact: no raw `dispatch()`/`event()` here).
 *
 * A provider failure on one payment is logged and skipped — it must never
 * abort the rest of the batch.
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
            }
        }

        return new ReconciliationSummary($checked, $updated, $errors);
    }

    private function reconcileOne(Payment $payment): bool
    {
        $gateway = $this->gateways->gatewayFor(ProviderCode::fromString($payment->providerCode));
        $receipt = $gateway->checkStatus($payment->providerTransactionRef);

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
        ));

        return true;
    }
}
