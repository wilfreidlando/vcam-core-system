<?php

declare(strict_types=1);

use Core\Payment\Domain\Model\Payment;
use Core\Payment\Domain\Model\SettlementAccount;
use Core\Payment\Domain\Model\SettlementException;
use Core\Payment\PaymentStatus;
use Core\Wallet\Contracts\WalletCreditSource;
use Core\Wallet\Domain\Model\SystemAccount;

function settlementPayment(?string $walletRecipient = 'customer-ext-1'): Payment
{
    return new Payment(
        id: 'pay-1', projectId: 'project-1', channel: 'mtn_momo', providerCode: 'my-coolpay', idempotencyKey: 'idem-1',
        providerTransactionRef: 'tx-1', amount: 5_000, currency: 'XAF', status: PaymentStatus::Succeeded,
        requiresAction: false, actionHint: null, paymentUrl: null, errorMessage: null, returnUrl: null,
        walletRecipientExternalRef: $walletRecipient,
    );
}

it('sees no exception when the confirmed amount and currency match, or when nothing was confirmed', function (): void {
    $payment = settlementPayment();

    expect($payment->settlementExceptionFor(5_000, 'XAF'))->toBeNull()
        ->and($payment->settlementExceptionFor(5_000, 'xaf'))->toBeNull()
        ->and($payment->settlementExceptionFor(null, null))->toBeNull();
});

it('flags a different amount, and a different currency first', function (): void {
    $payment = settlementPayment();

    expect($payment->settlementExceptionFor(4_999, 'XAF'))->toBe(SettlementException::AmountMismatch)
        ->and($payment->settlementExceptionFor(5_001, null))->toBe(SettlementException::AmountMismatch)
        ->and($payment->settlementExceptionFor(4_000, 'EUR'))->toBe(SettlementException::CurrencyMismatch);
});

it('never asks to credit a wallet for a settlement in exception', function (): void {
    $inException = settlementPayment()->withSettlement(4_000, 'XAF', SettlementException::AmountMismatch);
    $verified = settlementPayment()->withSettlement(5_000, 'XAF', null);

    expect($inException->shouldCreditWallet())->toBeFalse()
        ->and($verified->shouldCreditWallet())->toBeTrue();
});

it('keeps the settlement facts across a later status change', function (): void {
    $payment = settlementPayment()
        ->withSettlement(4_000, 'xaf', SettlementException::AmountMismatch)
        ->withStatusChange(PaymentStatus::Refunded, null);

    expect($payment->confirmedAmount)->toBe(4_000)
        ->and($payment->confirmedCurrency)->toBe('XAF')
        ->and($payment->settlementException)->toBe(SettlementException::AmountMismatch);
});

it('books settlements against the very clearing account a wallet credit uses', function (): void {
    expect(SettlementAccount::CLEARING)->toBe(SystemAccount::CLEARING)
        ->and(SettlementAccount::PROJECT_LEVEL)->toBe(SystemAccount::PROJECT_LEVEL);
});

it('keeps the wallet credit sources aligned with the settlement accounts', function (): void {
    // Neither module may import the other's Domain (G3c): the strings are
    // pinned together instead.
    expect(WalletCreditSource::Clearing->value)->toBe(SettlementAccount::CLEARING)
        ->and(WalletCreditSource::PaymentExceptions->value)->toBe(SettlementAccount::PAYMENT_EXCEPTIONS);
});
