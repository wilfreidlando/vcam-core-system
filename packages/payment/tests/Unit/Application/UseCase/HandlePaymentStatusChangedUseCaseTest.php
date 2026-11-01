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
use Core\Payment\Tests\Unit\Fakes\InMemoryMoneyExceptionRepository;
use Core\Payment\Tests\Unit\Fakes\InMemoryPaymentRepository;
use Core\Payment\Tests\Unit\Fakes\PassthroughUnitOfWork;
use Core\Payment\Tests\Unit\Fakes\RecordingSettlementLedger;
use Core\Payment\Tests\Unit\Fakes\SequentialIdentifier;
use Psr\Log\NullLogger;

/**
 * @return array{0: HandlePaymentStatusChangedUseCase, 1: InMemoryPaymentRepository, 2: FakePartyFinder, 3: FakeCreditWallet, 4: InMemoryEventOutbox, 5: RecordingSettlementLedger, 6: InMemoryMoneyExceptionRepository}
 */
function makeHandler(): array
{
    $payments = new InMemoryPaymentRepository;
    $partyFinder = new FakePartyFinder;
    $creditWallet = new FakeCreditWallet;
    $outbox = new InMemoryEventOutbox;
    $ledger = new RecordingSettlementLedger;
    $moneyExceptions = new InMemoryMoneyExceptionRepository;

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
        $ledger,
        $moneyExceptions,
    );

    return [$handler, $payments, $partyFinder, $creditWallet, $outbox, $ledger, $moneyExceptions];
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

function statusChangedEvent(PaymentStatus $status, ?string $reason = null, ?int $confirmedAmount = null, ?string $confirmedCurrency = null): PaymentStatusChanged
{
    return new PaymentStatusChanged(
        providerCode: 'my-coolpay',
        transactionRef: 'tx-1',
        appTransactionRef: 'idem-1',
        status: $status,
        reason: $reason,
        confirmedAmountMinor: $confirmedAmount,
        confirmedCurrency: $confirmedCurrency,
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

it('refuses to move a succeeded payment backwards and traces it (ADR-0061)', function (): void {
    $payments = new InMemoryPaymentRepository;
    $timeline = new Core\Payment\Tests\Unit\Fakes\InMemoryPaymentTimeline;
    $outbox = new InMemoryEventOutbox;

    $payments->create(new Payment(
        id: 'pay-1', projectId: 'project-1', channel: 'mtn_momo', providerCode: 'my-coolpay', idempotencyKey: 'idem-1',
        providerTransactionRef: 'tx-1', amount: 1000, currency: 'XAF', status: PaymentStatus::Succeeded,
        requiresAction: false, actionHint: null, paymentUrl: null, errorMessage: null, returnUrl: null, walletRecipientExternalRef: null,
    ));

    $useCase = new HandlePaymentStatusChangedUseCase(
        new PassthroughUnitOfWork,
        $payments,
        new FakePartyFinder,
        new FakeCreditWallet,
        $outbox,
        new SequentialIdentifier,
        new FixedClock,
        new NullLogger,
        Core\Payment\Tests\Unit\Fakes\InMemoryPaymentTimeline::journey($timeline),
        new RecordingSettlementLedger,
        new InMemoryMoneyExceptionRepository,
    );

    $useCase->handle(new PaymentStatusChanged(
        providerCode: 'my-coolpay', transactionRef: 'tx-1', appTransactionRef: 'idem-1',
        status: PaymentStatus::Failed, reason: 'late callback', occurredAt: null,
    ));

    expect($payments->find('pay-1')?->status)->toBe(PaymentStatus::Succeeded)
        ->and($timeline->types())->toBe(['status_change_ignored']);
});

// ADR-0076 §3 / ADR-0077 — every settled payment is booked exactly once.

it('books a direct collection to the ledger when no wallet was requested (ADR-0076 §3)', function (): void {
    [$handler, $payments, , $creditWallet, , $ledger] = makeHandler();
    $payments->create(pendingPayment(walletRecipientExternalRef: null));

    $handler->handle(statusChangedEvent(PaymentStatus::Succeeded, confirmedAmount: 1_000, confirmedCurrency: 'XAF'));

    expect($ledger->direct)->toBe(['payment-1'])
        ->and($ledger->unallocated)->toBe([])
        ->and($creditWallet->credited)->toHaveCount(0)
        ->and($payments->find('payment-1')?->confirmedAmount)->toBe(1_000)
        ->and($payments->find('payment-1')?->settlementException)->toBeNull();
});

it('books the wallet credit only, never a second settlement, when a wallet was requested', function (): void {
    [$handler, $payments, , $creditWallet, , $ledger] = makeHandler();
    $payments->create(pendingPayment(walletRecipientExternalRef: 'customer-ext-1'));

    $handler->handle(statusChangedEvent(PaymentStatus::Succeeded, confirmedAmount: 1_000, confirmedCurrency: 'XAF'));

    expect($creditWallet->credited)->toHaveCount(1)
        ->and($ledger->direct)->toBe([])
        ->and($ledger->unallocated)->toBe([]);
});

it('books nothing for a payment that did not succeed', function (): void {
    [$handler, $payments, , , , $ledger] = makeHandler();
    $payments->create(pendingPayment());

    $handler->handle(statusChangedEvent(PaymentStatus::Failed, 'insufficient funds', 1_000, 'XAF'));

    expect($ledger->direct)->toBe([])->and($ledger->unallocated)->toBe([]);
});

it('never credits a wallet when the provider confirmed another amount: it goes to payment_exceptions (ADR-0077)', function (): void {
    [$handler, $payments, , $creditWallet, $outbox, $ledger] = makeHandler();
    $payments->create(pendingPayment(walletRecipientExternalRef: 'customer-ext-1'));

    $handler->handle(statusChangedEvent(PaymentStatus::Succeeded, confirmedAmount: 800, confirmedCurrency: 'XAF'));

    $payment = $payments->find('payment-1');
    expect($payment?->status)->toBe(PaymentStatus::Succeeded)
        ->and($payment?->settlementException)->toBe(Core\Payment\Domain\Model\SettlementException::AmountMismatch)
        ->and($creditWallet->credited)->toHaveCount(0)
        ->and($ledger->direct)->toBe([])
        ->and($ledger->unallocated)->toBe([['paymentId' => 'payment-1', 'amount' => 800, 'currency' => 'XAF']])
        ->and($outbox->events[0]->payload['confirmed_amount'])->toBe(800)
        ->and($outbox->events[0]->payload['exception'])->toBe('amount_mismatch');
});

it('treats a different confirmed currency as an exception, booked in that currency', function (): void {
    [$handler, $payments, , , , $ledger] = makeHandler();
    $payments->create(pendingPayment());

    $handler->handle(statusChangedEvent(PaymentStatus::Succeeded, confirmedAmount: 1_000, confirmedCurrency: 'eur'));

    expect($payments->find('payment-1')?->settlementException)->toBe(Core\Payment\Domain\Model\SettlementException::CurrencyMismatch)
        ->and($ledger->unallocated)->toBe([['paymentId' => 'payment-1', 'amount' => 1_000, 'currency' => 'EUR']]);
});

it('keeps the exception on the payment when its currency cannot be booked', function (): void {
    [$handler, $payments, , $creditWallet, , $ledger] = makeHandler();
    $ledger->supportsCurrency = false;
    $payments->create(pendingPayment(walletRecipientExternalRef: 'customer-ext-1'));

    $handler->handle(statusChangedEvent(PaymentStatus::Succeeded, confirmedAmount: 1_000, confirmedCurrency: 'ZZZ'));

    expect($payments->find('payment-1')?->settlementException)->toBe(Core\Payment\Domain\Model\SettlementException::CurrencyMismatch)
        ->and($creditWallet->credited)->toHaveCount(0)
        ->and($ledger->unallocated)->toBe([]);
});

it('settles on the requested amount, traced as not verified, when the source confirms no amount', function (): void {
    [$handler, $payments, , , $outbox, $ledger] = makeHandler();
    $payments->create(pendingPayment());

    $handler->handle(statusChangedEvent(PaymentStatus::Succeeded));

    expect($ledger->direct)->toBe(['payment-1'])
        ->and($payments->find('payment-1')?->confirmedAmount)->toBeNull()
        ->and($outbox->events[0]->payload['confirmed_amount'])->toBeNull()
        ->and($outbox->events[0]->payload['exception'])->toBeNull();
});

it('does not book a second settlement when the same success is replayed', function (): void {
    [$handler, $payments, , , , $ledger] = makeHandler();
    $payments->create(pendingPayment());

    $handler->handle(statusChangedEvent(PaymentStatus::Succeeded, confirmedAmount: 1_000, confirmedCurrency: 'XAF'));
    $handler->handle(statusChangedEvent(PaymentStatus::Succeeded, confirmedAmount: 1_000, confirmedCurrency: 'XAF'));

    expect($ledger->direct)->toBe(['payment-1']);
});

it('opens one money exception for a mismatch, never a second one on replay (ADR-0077 §3)', function (): void {
    [$handler, $payments, , , , , $moneyExceptions] = makeHandler();
    $payments->create(pendingPayment(walletRecipientExternalRef: 'customer-ext-1'));

    $handler->handle(statusChangedEvent(PaymentStatus::Succeeded, confirmedAmount: 800, confirmedCurrency: 'XAF'));
    $handler->handle(statusChangedEvent(PaymentStatus::Succeeded, confirmedAmount: 800, confirmedCurrency: 'XAF'));

    $all = $moneyExceptions->all();
    expect($all)->toHaveCount(1)
        ->and($all[0]->kind)->toBe(Core\Payment\Domain\Model\MoneyExceptionKind::AmountMismatch)
        ->and($all[0]->amount)->toBe(800)
        ->and($all[0]->bookedAmount)->toBe(800)
        ->and($all[0]->paymentId)->toBe('payment-1')
        ->and($all[0]->status)->toBe(Core\Payment\Domain\Model\MoneyExceptionStatus::Open);
});

it('opens a money exception with nothing booked when the currency cannot be booked', function (): void {
    [$handler, $payments, , , , $ledger, $moneyExceptions] = makeHandler();
    $ledger->supportsCurrency = false;
    $payments->create(pendingPayment());

    $handler->handle(statusChangedEvent(PaymentStatus::Succeeded, confirmedAmount: 1_000, confirmedCurrency: 'ZZZ'));

    expect($moneyExceptions->all()[0]->bookedAmount)->toBe(0)
        ->and($moneyExceptions->all()[0]->allowedResolutions())->toBe([Core\Payment\Domain\Model\MoneyExceptionResolution::NoAction]);
});

// Review finding: a reconciliation (no confirmed amount) settling a payment
// before the provider's callback must not let the callback's amount go unchecked.

it('records a late confirmation that matches, without booking anything again', function (): void {
    [$handler, $payments, , , , $ledger, $moneyExceptions] = makeHandler();
    $payments->create(pendingPayment());

    $handler->handle(statusChangedEvent(PaymentStatus::Succeeded), 'reconciliation');
    $handler->handle(statusChangedEvent(PaymentStatus::Succeeded, confirmedAmount: 1_000, confirmedCurrency: 'XAF'));

    expect($payments->find('payment-1')?->confirmedAmount)->toBe(1_000)
        ->and($payments->find('payment-1')?->settlementException)->toBeNull()
        ->and($ledger->direct)->toBe(['payment-1'])
        ->and($moneyExceptions->all())->toBe([]);
});

it('opens a critical exception, with nothing booked, for a late confirmation in mismatch', function (): void {
    [$handler, $payments, , $creditWallet, $outbox, $ledger, $moneyExceptions] = makeHandler();
    $payments->create(pendingPayment(walletRecipientExternalRef: 'customer-ext-1'));

    $handler->handle(statusChangedEvent(PaymentStatus::Succeeded), 'reconciliation');
    $handler->handle(statusChangedEvent(PaymentStatus::Succeeded, confirmedAmount: 600, confirmedCurrency: 'XAF'));
    // A replay of the same late callback changes nothing more.
    $handler->handle(statusChangedEvent(PaymentStatus::Succeeded, confirmedAmount: 600, confirmedCurrency: 'XAF'));

    $exceptions = $moneyExceptions->all();
    expect($exceptions)->toHaveCount(1)
        ->and($exceptions[0]->severity)->toBe(Core\Payment\Domain\Model\MoneyExceptionSeverity::Critical)
        ->and($exceptions[0]->bookedAmount)->toBe(0)
        ->and($exceptions[0]->amount)->toBe(600)
        ->and($payments->find('payment-1')?->settlementException)->toBe(Core\Payment\Domain\Model\SettlementException::AmountMismatch)
        ->and($creditWallet->credited)->toHaveCount(1)
        ->and($ledger->unallocated)->toBe([])
        ->and($outbox->events)->toHaveCount(1);
});
