<?php

declare(strict_types=1);

namespace Core\Payment\Application\UseCase;

use Core\Payment\Contracts\Events\PaymentStatusChanged;
use Core\Payment\Contracts\PaymentGatewayRegistry;
use Core\Payment\Contracts\PaymentReconciliationResult;
use Core\Payment\Contracts\PaymentStatusChangeHandler;
use Core\Payment\Contracts\ReconcilePayment;
use Core\Payment\Contracts\ReconcilePaymentCommand;
use Core\Payment\Domain\Exception\PaymentNotFound;
use Core\Payment\Domain\Port\PaymentRepository;
use Core\Support\Clock;
use Core\Support\Integration\Provider\ProviderCode;
use DateTimeZone;

/**
 * Same convergence path as `ReconcileStuckPaymentsUseCase::reconcileOne()`
 * (kept as a small, intentional duplication rather than a forced shared
 * abstraction across the two call sites — see `ReconcilePayment`'s
 * docblock) but for exactly one, id-selected payment, and without
 * swallowing the provider/handler exception: a single on-demand check
 * triggered from the console should surface its own failure to the
 * operator who asked for it, not log-and-skip like the batch job does.
 */
final readonly class ReconcilePaymentUseCase implements ReconcilePayment
{
    public function __construct(
        private PaymentRepository $payments,
        private PaymentGatewayRegistry $gateways,
        private PaymentStatusChangeHandler $statusChangeHandler,
        private Clock $clock,
    ) {}

    public function reconcile(ReconcilePaymentCommand $command): PaymentReconciliationResult
    {
        $payment = $this->payments->find($command->paymentId);
        if ($payment === null) {
            // A dedicated, never-thrown-elsewhere-in-this-path exception —
            // not a bare RuntimeException — so the caller can distinguish
            // "no such payment" from a provider-side failure (both of
            // which are `RuntimeException` subtypes: `checkStatus()` can
            // throw `ProviderApplicationNotFound`, also a RuntimeException,
            // for a legitimately-existing payment whose project has no
            // registered provider application). Caught this the hard way:
            // a live run against seeded data reported "payment not found"
            // for a payment that demonstrably existed, because the
            // original bare `RuntimeException` here was indistinguishable
            // from `ProviderApplicationNotFound` to a generic catch block.
            throw PaymentNotFound::withId($command->paymentId);
        }

        $statusBefore = $payment->status->value;

        $gateway = $this->gateways->gatewayFor(ProviderCode::fromString($payment->providerCode));
        $receipt = $gateway->checkStatus($payment->providerTransactionRef, $payment->projectId);

        if ($receipt->status === $payment->status) {
            return new PaymentReconciliationResult(
                changed: false,
                statusBefore: $statusBefore,
                statusAfter: $statusBefore,
            );
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

        return new PaymentReconciliationResult(
            changed: true,
            statusBefore: $statusBefore,
            statusAfter: $receipt->status->value,
        );
    }
}
