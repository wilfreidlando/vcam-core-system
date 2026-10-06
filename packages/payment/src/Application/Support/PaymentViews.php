<?php

declare(strict_types=1);

namespace Core\Payment\Application\Support;

use Core\Payment\Contracts\PaymentView;
use Core\Payment\Domain\Model\Payment;
use DateTimeInterface;

/**
 * One mapping from the `Payment` aggregate to its read model, shared by
 * every use case that returns one (start, status, list, simulate).
 */
final class PaymentViews
{
    public static function from(Payment $payment, bool $includeCallbackSecret = false): PaymentView
    {
        return new PaymentView(
            id: $payment->id,
            projectId: $payment->projectId,
            channel: $payment->channel,
            status: $payment->status->value,
            amount: $payment->amount,
            currency: $payment->currency,
            requiresAction: $payment->requiresAction,
            actionHint: $payment->actionHint,
            paymentUrl: $payment->paymentUrl,
            errorMessage: $payment->errorMessage,
            idempotencyKey: $payment->idempotencyKey,
            callbackUrl: $payment->callbackUrl,
            callbackSecret: $includeCallbackSecret ? $payment->callbackSecret : null,
            isFinal: $payment->status->isFinal(),
            environment: $payment->environment->value,
            providerCode: $payment->providerCode,
            providerTransactionRef: $payment->providerTransactionRef,
            returnUrl: $payment->returnUrl,
            walletRecipientExternalRef: $payment->walletRecipientExternalRef,
            createdAt: $payment->createdAt?->format(DateTimeInterface::RFC3339),
            updatedAt: $payment->updatedAt?->format(DateTimeInterface::RFC3339),
        );
    }
}
