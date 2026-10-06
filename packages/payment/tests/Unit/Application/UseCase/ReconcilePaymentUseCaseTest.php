<?php

declare(strict_types=1);

use Core\Payment\Application\UseCase\ReconcilePaymentUseCase;
use Core\Payment\Contracts\PaymentReceipt;
use Core\Payment\Contracts\ReconcilePaymentCommand;
use Core\Payment\Domain\Model\Payment;
use Core\Payment\PaymentStatus;
use Core\Payment\Tests\Unit\Fakes\FakePaymentGateway;
use Core\Payment\Tests\Unit\Fakes\FakePaymentGatewayRegistry;
use Core\Payment\Tests\Unit\Fakes\FakePaymentStatusChangeHandler;
use Core\Payment\Tests\Unit\Fakes\FixedClock;
use Core\Payment\Tests\Unit\Fakes\InMemoryPaymentRepository;
use Core\Support\Integration\Provider\ProviderCode;

function paymentForReconciliation(string $id, PaymentStatus $status = PaymentStatus::Processing): Payment
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

it('throws PaymentNotFound — never a bare RuntimeException a provider failure could also throw — when the payment does not exist', function (): void {
    $useCase = new ReconcilePaymentUseCase(
        payments: new InMemoryPaymentRepository,
        gateways: new FakePaymentGatewayRegistry,
        statusChangeHandler: new FakePaymentStatusChangeHandler,
        clock: new FixedClock,
        journey: Core\Payment\Tests\Unit\Fakes\InMemoryPaymentTimeline::journey(),
    );

    $useCase->reconcile(new ReconcilePaymentCommand('missing'));
})->throws(Core\Payment\Domain\Exception\PaymentNotFound::class);

it('reports no change when the provider confirms the same status', function (): void {
    $payments = new InMemoryPaymentRepository;
    $payments->create(paymentForReconciliation('p1', PaymentStatus::Processing));

    $gateway = new FakePaymentGateway(ProviderCode::fromString('fake-provider'));
    $gateway->nextReceipt = new PaymentReceipt('tx-p1', PaymentStatus::Processing);
    $registry = new FakePaymentGatewayRegistry;
    $registry->register($gateway);

    $handler = new FakePaymentStatusChangeHandler;
    $useCase = new ReconcilePaymentUseCase(
        payments: $payments,
        gateways: $registry,
        statusChangeHandler: $handler,
        clock: new FixedClock,
        journey: Core\Payment\Tests\Unit\Fakes\InMemoryPaymentTimeline::journey(),
    );

    $result = $useCase->reconcile(new ReconcilePaymentCommand('p1'));

    expect($result->changed)->toBeFalse()
        ->and($result->statusBefore)->toBe('processing')
        ->and($result->statusAfter)->toBe('processing')
        ->and($handler->handled)->toBeEmpty();
});

it('converges on the status-change handler when the provider reports a different status', function (): void {
    $payments = new InMemoryPaymentRepository;
    $payments->create(paymentForReconciliation('p1', PaymentStatus::Processing));

    $gateway = new FakePaymentGateway(ProviderCode::fromString('fake-provider'));
    $gateway->nextReceipt = new PaymentReceipt('tx-p1', PaymentStatus::Succeeded);
    $registry = new FakePaymentGatewayRegistry;
    $registry->register($gateway);

    $handler = new FakePaymentStatusChangeHandler;
    $useCase = new ReconcilePaymentUseCase(
        payments: $payments,
        gateways: $registry,
        statusChangeHandler: $handler,
        clock: new FixedClock,
        journey: Core\Payment\Tests\Unit\Fakes\InMemoryPaymentTimeline::journey(),
    );

    $result = $useCase->reconcile(new ReconcilePaymentCommand('p1'));

    expect($result->changed)->toBeTrue()
        ->and($result->statusBefore)->toBe('processing')
        ->and($result->statusAfter)->toBe('succeeded')
        ->and($handler->handled)->toHaveCount(1);

    $event = $handler->handled[0];
    expect($event->providerCode)->toBe('fake-provider')
        ->and($event->transactionRef)->toBe('tx-p1')
        ->and($event->status)->toBe(PaymentStatus::Succeeded);
});

it('lets a provider failure propagate rather than swallowing it', function (): void {
    $payments = new InMemoryPaymentRepository;
    $payments->create(paymentForReconciliation('p1'));

    $gateway = new FakePaymentGateway(ProviderCode::fromString('fake-provider'));
    $gateway->checkStatusException = new Core\Support\Integration\Exception\ProviderTransientError('boom', $gateway->providerCode());
    $registry = new FakePaymentGatewayRegistry;
    $registry->register($gateway);

    $useCase = new ReconcilePaymentUseCase(
        payments: $payments,
        gateways: $registry,
        statusChangeHandler: new FakePaymentStatusChangeHandler,
        clock: new FixedClock,
        journey: Core\Payment\Tests\Unit\Fakes\InMemoryPaymentTimeline::journey(),
    );

    $useCase->reconcile(new ReconcilePaymentCommand('p1'));
})->throws(Core\Support\Integration\Exception\ProviderTransientError::class);
