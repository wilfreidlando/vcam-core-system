<?php

declare(strict_types=1);

use Core\Payment\Application\UseCase\ReconcileStuckPaymentsUseCase;
use Core\Payment\Contracts\PaymentReceipt;
use Core\Payment\Contracts\ReconcileStuckPaymentsCommand;
use Core\Payment\Domain\Model\Payment;
use Core\Payment\PaymentStatus;
use Core\Payment\Tests\Unit\Fakes\FakePaymentGateway;
use Core\Payment\Tests\Unit\Fakes\FakePaymentGatewayRegistry;
use Core\Payment\Tests\Unit\Fakes\FakePaymentStatusChangeHandler;
use Core\Payment\Tests\Unit\Fakes\FixedClock;
use Core\Payment\Tests\Unit\Fakes\InMemoryPaymentRepository;
use Core\Support\Integration\Exception\ProviderTransientError;
use Core\Support\Integration\Provider\ProviderCode;
use Psr\Log\NullLogger;

function stuckPayment(string $id, PaymentStatus $status = PaymentStatus::Processing): Payment
{
    return new Payment(
        id: $id,
        projectId: 'project-1',
        channel: 'mtn_momo',
        providerCode: 'fake-provider',
        idempotencyKey: 'idem-'.$id,
        providerTransactionRef: 'tx-'.$id,
        amount: 1000,
        currency: 'XAF',
        status: $status,
        requiresAction: false,
        actionHint: null,
        paymentUrl: null,
        errorMessage: null,
        returnUrl: null,
        walletRecipientExternalRef: null,
    );
}

it('does nothing when no payment is stuck', function (): void {
    $handler = new FakePaymentStatusChangeHandler;
    $useCase = new ReconcileStuckPaymentsUseCase(
        payments: new InMemoryPaymentRepository,
        gateways: new FakePaymentGatewayRegistry,
        statusChangeHandler: $handler,
        clock: new FixedClock,
        logger: new NullLogger,
        stuckAfterMinutes: 15,
        batchSize: 100,
    );

    $summary = $useCase->reconcile(new ReconcileStuckPaymentsCommand);

    expect($summary->checked)->toBe(0)
        ->and($summary->updated)->toBe(0)
        ->and($summary->errors)->toBe(0)
        ->and($handler->handled)->toBeEmpty();
});

it('does not call the handler when checkStatus reports the same status', function (): void {
    $payments = new InMemoryPaymentRepository;
    $payments->create(stuckPayment('p1', PaymentStatus::Processing));

    $gateway = new FakePaymentGateway(ProviderCode::fromString('fake-provider'));
    $gateway->nextReceipt = new PaymentReceipt('tx-p1', PaymentStatus::Processing);
    $registry = new FakePaymentGatewayRegistry;
    $registry->register($gateway);

    $handler = new FakePaymentStatusChangeHandler;
    $useCase = new ReconcileStuckPaymentsUseCase(
        payments: $payments,
        gateways: $registry,
        statusChangeHandler: $handler,
        clock: new FixedClock,
        logger: new NullLogger,
        stuckAfterMinutes: 15,
        batchSize: 100,
    );

    $summary = $useCase->reconcile(new ReconcileStuckPaymentsCommand);

    expect($summary->checked)->toBe(1)
        ->and($summary->updated)->toBe(0)
        ->and($handler->handled)->toBeEmpty();
});

it('hands the handler a correctly-shaped event when the provider reports a settled status', function (): void {
    $payments = new InMemoryPaymentRepository;
    $payments->create(stuckPayment('p1'));

    $gateway = new FakePaymentGateway(ProviderCode::fromString('fake-provider'));
    $gateway->nextReceipt = new PaymentReceipt('tx-p1', PaymentStatus::Succeeded);
    $registry = new FakePaymentGatewayRegistry;
    $registry->register($gateway);

    $handler = new FakePaymentStatusChangeHandler;
    $useCase = new ReconcileStuckPaymentsUseCase(
        payments: $payments,
        gateways: $registry,
        statusChangeHandler: $handler,
        clock: new FixedClock,
        logger: new NullLogger,
        stuckAfterMinutes: 15,
        batchSize: 100,
    );

    $summary = $useCase->reconcile(new ReconcileStuckPaymentsCommand);

    expect($summary->checked)->toBe(1)
        ->and($summary->updated)->toBe(1)
        ->and($handler->handled)->toHaveCount(1);

    $event = $handler->handled[0];
    expect($event->providerCode)->toBe('fake-provider')
        ->and($event->transactionRef)->toBe('tx-p1')
        ->and($event->appTransactionRef)->toBe('idem-p1')
        ->and($event->status)->toBe(PaymentStatus::Succeeded);
});

it('logs and skips a payment whose provider call fails, without aborting the batch', function (): void {
    $payments = new InMemoryPaymentRepository;
    $payments->create(stuckPayment('p1'));
    $payments->create(stuckPayment('p2'));

    $failingGateway = new FakePaymentGateway(ProviderCode::fromString('fake-provider'));
    $failingGateway->checkStatusException = new ProviderTransientError('boom', $failingGateway->providerCode());
    $registry = new FakePaymentGatewayRegistry;
    $registry->register($failingGateway);

    $handler = new FakePaymentStatusChangeHandler;
    $useCase = new ReconcileStuckPaymentsUseCase(
        payments: $payments,
        gateways: $registry,
        statusChangeHandler: $handler,
        clock: new FixedClock,
        logger: new NullLogger,
        stuckAfterMinutes: 15,
        batchSize: 100,
    );

    $summary = $useCase->reconcile(new ReconcileStuckPaymentsCommand);

    expect($summary->checked)->toBe(2)
        ->and($summary->updated)->toBe(0)
        ->and($summary->errors)->toBe(2)
        ->and($handler->handled)->toBeEmpty();
});

it('logs and skips a payment whose handler fails with a non-provider exception, without aborting the batch', function (): void {
    // Review finding, Lot 6 follow-up: the catch clause previously only
    // covered provider-shaped exceptions, so a persistence/domain
    // failure inside PaymentStatusChangeHandler (a real possibility —
    // see the row-lock race fix) escaped and aborted the whole loop,
    // leaving every payment after the poisoned one unchecked.
    $payments = new InMemoryPaymentRepository;
    $payments->create(stuckPayment('p1'));
    $payments->create(stuckPayment('p2'));

    $gateway = new FakePaymentGateway(ProviderCode::fromString('fake-provider'));
    $gateway->nextReceipt = new PaymentReceipt('tx-x', PaymentStatus::Succeeded);
    $registry = new FakePaymentGatewayRegistry;
    $registry->register($gateway);

    $handler = new FakePaymentStatusChangeHandler;
    $handler->handleException = new LogicException('a persistence race, not a provider failure');

    $useCase = new ReconcileStuckPaymentsUseCase(
        payments: $payments,
        gateways: $registry,
        statusChangeHandler: $handler,
        clock: new FixedClock,
        logger: new NullLogger,
        stuckAfterMinutes: 15,
        batchSize: 100,
    );

    $summary = $useCase->reconcile(new ReconcileStuckPaymentsCommand);

    expect($summary->checked)->toBe(2)
        ->and($summary->updated)->toBe(0)
        ->and($summary->errors)->toBe(2)
        ->and($handler->handled)->toBeEmpty();
});

it('honours limitOverride over the configured batch size', function (): void {
    $payments = new InMemoryPaymentRepository;
    $payments->create(stuckPayment('p1'));
    $payments->create(stuckPayment('p2'));
    $payments->create(stuckPayment('p3'));

    $gateway = new FakePaymentGateway(ProviderCode::fromString('fake-provider'));
    $gateway->nextReceipt = new PaymentReceipt('tx-x', PaymentStatus::Processing);
    $registry = new FakePaymentGatewayRegistry;
    $registry->register($gateway);

    $useCase = new ReconcileStuckPaymentsUseCase(
        payments: $payments,
        gateways: $registry,
        statusChangeHandler: new FakePaymentStatusChangeHandler,
        clock: new FixedClock,
        logger: new NullLogger,
        stuckAfterMinutes: 15,
        batchSize: 100,
    );

    $summary = $useCase->reconcile(new ReconcileStuckPaymentsCommand(limitOverride: 2));

    expect($summary->checked)->toBe(2);
});
