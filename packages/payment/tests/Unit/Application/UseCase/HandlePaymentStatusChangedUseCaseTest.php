<?php

declare(strict_types=1);

use Core\Payment\Application\UseCase\HandlePaymentStatusChangedUseCase;
use Core\Payment\Contracts\Events\PaymentStatusChanged;
use Core\Payment\Domain\Model\Payment;
use Core\Payment\PaymentStatus;
use Core\Payment\Tests\Unit\Fakes\FakeCreditWallet;
use Core\Payment\Tests\Unit\Fakes\FakePartyFinder;
use Core\Payment\Tests\Unit\Fakes\FixedClock;
use Core\Payment\Tests\Unit\Fakes\InMemoryEventOutbox;
use Core\Payment\Tests\Unit\Fakes\InMemoryPaymentRepository;
use Core\Payment\Tests\Unit\Fakes\PassthroughUnitOfWork;
use Core\Payment\Tests\Unit\Fakes\SequentialIdentifier;
use Psr\Log\NullLogger;

/**
 * @return array{0: HandlePaymentStatusChangedUseCase, 1: InMemoryPaymentRepository, 2: FakePartyFinder, 3: FakeCreditWallet, 4: InMemoryEventOutbox}
 */
function makeHandler(): array
{
    $payments = new InMemoryPaymentRepository;
    $partyFinder = new FakePartyFinder;
    $creditWallet = new FakeCreditWallet;
    $outbox = new InMemoryEventOutbox;

    $handler = new HandlePaymentStatusChangedUseCase(
        new PassthroughUnitOfWork,
        $payments,
        $partyFinder,
        $creditWallet,
        $outbox,
        new SequentialIdentifier,
        new FixedClock,
        new NullLogger,
        Core\Payment\Tests\Unit\Fakes\InMemoryPaymentTimeline::journey(),
    );

    return [$handler, $payments, $partyFinder, $creditWallet, $outbox];
}

function pendingPayment(?string $walletRecipientExternalRef = null, ?string $callbackUrl = null): Payment
{
    return new Payment(
        id: 'payment-1',
        projectId: 'project-1',
        channel: 'mtn_momo',
        providerCode: 'my-coolpay',
        idempotencyKey: 'idem-1',
        providerTransactionRef: 'tx-1',
        amount: 1_000,
        currency: 'XAF',
        status: PaymentStatus::Pending,
        requiresAction: false,
        actionHint: null,
        paymentUrl: null,
        errorMessage: null,
        returnUrl: null,
        walletRecipientExternalRef: $walletRecipientExternalRef,
        callbackUrl: $callbackUrl,
    );
}

function statusChangedEvent(PaymentStatus $status, ?string $reason = null): PaymentStatusChanged
{
    return new PaymentStatusChanged(
        providerCode: 'my-coolpay',
        transactionRef: 'tx-1',
        appTransactionRef: 'idem-1',
        status: $status,
        reason: $reason,
    );
}

it('updates the payment status and publishes the canonical event', function (): void {
    [$handler, $payments, , , $outbox] = makeHandler();
    $payments->create(pendingPayment());

    $handler->handle(statusChangedEvent(PaymentStatus::Succeeded));

    expect($payments->find('payment-1')?->status)->toBe(PaymentStatus::Succeeded)
        ->and($outbox->events)->toHaveCount(1)
        ->and($outbox->events[0]->type)->toBe('payment.status_changed');
});

it('does NOT credit a wallet when no recipient was requested at initiation (ADR-0037)', function (): void {
    [$handler, $payments, $partyFinder, $creditWallet] = makeHandler();
    $payments->create(pendingPayment(walletRecipientExternalRef: null));

    $handler->handle(statusChangedEvent(PaymentStatus::Succeeded));

    expect($partyFinder->calls)->toHaveCount(0)
        ->and($creditWallet->credited)->toHaveCount(0);
});

it('credits the wallet synchronously when the caller asked for it and the payment succeeded', function (): void {
    [$handler, $payments, $partyFinder, $creditWallet] = makeHandler();
    $payments->create(pendingPayment(walletRecipientExternalRef: 'customer-ext-1'));

    $handler->handle(statusChangedEvent(PaymentStatus::Succeeded));

    expect($partyFinder->calls)->toHaveCount(1)
        ->and($partyFinder->calls[0]->externalRef)->toBe('customer-ext-1')
        ->and($creditWallet->credited)->toHaveCount(1)
        ->and($creditWallet->credited[0]->amountMinor)->toBe(1_000)
        ->and($creditWallet->credited[0]->currency)->toBe('XAF')
        ->and($creditWallet->credited[0]->idempotencyKey)->toBe('payment-1');
});

it('never credits a wallet for a non-success terminal status, even with a recipient requested', function (): void {
    [$handler, $payments, , $creditWallet] = makeHandler();
    $payments->create(pendingPayment(walletRecipientExternalRef: 'customer-ext-1'));

    $handler->handle(statusChangedEvent(PaymentStatus::Failed, 'insufficient funds'));

    expect($creditWallet->credited)->toHaveCount(0)
        ->and($payments->find('payment-1')?->status)->toBe(PaymentStatus::Failed);
});

it('is idempotent: a replayed webhook after the payment already settled has no second effect', function (): void {
    [$handler, $payments, , $creditWallet, $outbox] = makeHandler();
    $payments->create(pendingPayment(walletRecipientExternalRef: 'customer-ext-1'));

    $handler->handle(statusChangedEvent(PaymentStatus::Succeeded));
    $handler->handle(statusChangedEvent(PaymentStatus::Succeeded));

    expect($creditWallet->credited)->toHaveCount(1, 'the wallet must be credited exactly once')
        ->and($outbox->events)->toHaveCount(1, 'the outbox must receive exactly one event');
});

it('flags the outbox event to skip the generic webhook fan-out when the payment has its own callback_url', function (): void {
    [$handler, $payments, , , $outbox] = makeHandler();
    $payments->create(pendingPayment(callbackUrl: 'https://caller.example.com/webhooks/core'));

    $handler->handle(statusChangedEvent(PaymentStatus::Succeeded));

    expect($outbox->events[0]->skipWebhookEndpointFanOut)->toBeTrue();
});

it('leaves the generic webhook fan-out enabled when the payment has no callback_url', function (): void {
    [$handler, $payments, , , $outbox] = makeHandler();
    $payments->create(pendingPayment(callbackUrl: null));

    $handler->handle(statusChangedEvent(PaymentStatus::Succeeded));

    expect($outbox->events[0]->skipWebhookEndpointFanOut)->toBeFalse();
});

it('ignores a webhook for a transaction this Core never initiated', function (): void {
    [$handler, $payments, , $creditWallet, $outbox] = makeHandler();
    // No payment seeded — nothing matches (providerCode, transactionRef).

    $handler->handle(statusChangedEvent(PaymentStatus::Succeeded));

    expect($payments->count())->toBe(0)
        ->and($creditWallet->credited)->toHaveCount(0)
        ->and($outbox->events)->toHaveCount(0);
});
