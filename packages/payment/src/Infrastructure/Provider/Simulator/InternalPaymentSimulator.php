<?php

declare(strict_types=1);

namespace Core\Payment\Infrastructure\Provider\Simulator;

use Core\Payment\Application\Port\PaymentSimulator;
use Core\Payment\Contracts\InitiatePaymentCommand;
use Core\Payment\Contracts\PaymentReceipt;
use Core\Payment\PaymentFlow;
use Core\Payment\PaymentStatus;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * ADR-0066 — the built-in simulated "provider". A paylink gets a payment
 * URL to the Core's own hosted checkout (signed, expires in 24 h) where the
 * payer picks the outcome; a payin gets an action hint saying how to
 * simulate it through the API.
 */
final readonly class InternalPaymentSimulator implements PaymentSimulator
{
    public function isEnabled(): bool
    {
        return (bool) config('payment.simulator.enabled', false);
    }

    public function initiate(InitiatePaymentCommand $command): PaymentReceipt
    {
        $transactionRef = 'sim_'.Str::lower((string) Str::ulid());

        if ($command->flow === PaymentFlow::Paylink) {
            return new PaymentReceipt(
                transactionRef: $transactionRef,
                status: PaymentStatus::Pending,
                paymentUrl: URL::temporarySignedRoute('simulator.checkout.show', now()->addDay(), ['transactionRef' => $transactionRef]),
            );
        }

        return new PaymentReceipt(
            transactionRef: $transactionRef,
            status: PaymentStatus::Pending,
            requiresAction: true,
            actionHint: 'SIMULATEUR — aucun paiement réel. Simulez le résultat avec POST /v1/payments/{id}/simulate.',
        );
    }
}
