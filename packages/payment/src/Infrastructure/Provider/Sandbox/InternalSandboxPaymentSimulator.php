<?php

declare(strict_types=1);

namespace Core\Payment\Infrastructure\Provider\Sandbox;

use Core\Payment\Application\Port\SandboxPaymentSimulator;
use Core\Payment\Contracts\InitiatePaymentCommand;
use Core\Payment\Contracts\PaymentReceipt;
use Core\Payment\PaymentFlow;
use Core\Payment\PaymentStatus;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * ADR-0060 — the built-in sandbox "provider". A paylink gets a payment
 * URL to the Core's own hosted sandbox checkout (signed, expires in 24 h)
 * where the payer picks the outcome; a payin gets an action hint saying how
 * to simulate it through the API.
 */
final readonly class InternalSandboxPaymentSimulator implements SandboxPaymentSimulator
{
    public function initiate(InitiatePaymentCommand $command): PaymentReceipt
    {
        $transactionRef = 'sbx_'.Str::lower((string) Str::ulid());

        if ($command->flow === PaymentFlow::Paylink) {
            return new PaymentReceipt(
                transactionRef: $transactionRef,
                status: PaymentStatus::Pending,
                paymentUrl: URL::temporarySignedRoute('sandbox.checkout.show', now()->addDay(), ['transactionRef' => $transactionRef]),
            );
        }

        return new PaymentReceipt(
            transactionRef: $transactionRef,
            status: PaymentStatus::Pending,
            requiresAction: true,
            actionHint: 'SANDBOX — aucun paiement réel. Simulez le résultat avec POST /v1/sandbox/payments/{id}/simulate.',
        );
    }
}
