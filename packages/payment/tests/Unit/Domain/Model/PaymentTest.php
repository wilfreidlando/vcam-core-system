<?php

declare(strict_types=1);

use Core\Payment\Domain\Model\Payment;
use Core\Payment\PaymentStatus;

function makePayment(
    PaymentStatus $status = PaymentStatus::Pending,
    ?string $walletRecipientExternalRef = null,
): Payment {
    return new Payment(
        id: 'payment-1',
        projectId: 'project-1',
        channel: 'mtn_momo',
        providerCode: 'my-coolpay',
        idempotencyKey: 'idem-1',
        providerTransactionRef: 'tx-1',
        amount: 1_000,
        currency: 'XAF',
        status: $status,
        requiresAction: false,
        actionHint: null,
        paymentUrl: null,
        errorMessage: null,
        returnUrl: null,
        walletRecipientExternalRef: $walletRecipientExternalRef,
    );
}

it('rejects a blank provider transaction reference', function (): void {
    new Payment(
        id: 'payment-1',
        projectId: 'project-1',
        channel: 'mtn_momo',
        providerCode: 'my-coolpay',
        idempotencyKey: 'idem-1',
        providerTransactionRef: '  ',
        amount: 1_000,
        currency: 'XAF',
        status: PaymentStatus::Pending,
        requiresAction: false,
        actionHint: null,
        paymentUrl: null,
        errorMessage: null,
        returnUrl: null,
        walletRecipientExternalRef: null,
    );
})->throws(InvalidArgumentException::class, 'non-blank provider transaction reference');

it('withStatusChange returns a new instance with the updated status and reason', function (): void {
    $payment = makePayment();

    $updated = $payment->withStatusChange(PaymentStatus::Failed, 'insufficient funds');

    expect($updated)->not->toBe($payment)
        ->and($updated->status)->toBe(PaymentStatus::Failed)
        ->and($updated->errorMessage)->toBe('insufficient funds')
        ->and($payment->status)->toBe(PaymentStatus::Pending, 'the original instance is untouched');
});

it('withStatusChange clears requiresAction and actionHint', function (): void {
    $payment = new Payment(
        id: 'payment-1',
        projectId: 'project-1',
        channel: 'mtn_momo',
        providerCode: 'my-coolpay',
        idempotencyKey: 'idem-1',
        providerTransactionRef: 'tx-1',
        amount: 1_000,
        currency: 'XAF',
        status: PaymentStatus::Pending,
        requiresAction: true,
        actionHint: 'enter the OTP sent by SMS',
        paymentUrl: null,
        errorMessage: null,
        returnUrl: null,
        walletRecipientExternalRef: null,
    );

    $updated = $payment->withStatusChange(PaymentStatus::Succeeded, null);

    expect($updated->requiresAction)->toBeFalse()
        ->and($updated->actionHint)->toBeNull();
});

it('shouldCreditWallet is true only when succeeded AND a recipient was requested', function (): void {
    expect(makePayment(PaymentStatus::Succeeded, 'ext-ref-1')->shouldCreditWallet())->toBeTrue()
        ->and(makePayment(PaymentStatus::Succeeded, null)->shouldCreditWallet())->toBeFalse()
        ->and(makePayment(PaymentStatus::Pending, 'ext-ref-1')->shouldCreditWallet())->toBeFalse()
        ->and(makePayment(PaymentStatus::Failed, 'ext-ref-1')->shouldCreditWallet())->toBeFalse();
});
