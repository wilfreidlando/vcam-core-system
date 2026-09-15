<?php

declare(strict_types=1);

use Core\Payment\Application\UseCase\StartPaymentUseCase;
use Core\Payment\Contracts\PaymentChannel;
use Core\Payment\Contracts\PaymentReceipt;
use Core\Payment\Contracts\StartPaymentCommand;
use Core\Payment\Domain\Model\Payment;
use Core\Payment\PaymentStatus;
use Core\Payment\Tests\Unit\Fakes\FakePaymentGateway;
use Core\Payment\Tests\Unit\Fakes\FakePaymentGatewayRegistry;
use Core\Payment\Tests\Unit\Fakes\FakePaymentProviderRouter;
use Core\Payment\Tests\Unit\Fakes\InMemoryPaymentRepository;
use Core\Payment\Tests\Unit\Fakes\SequentialIdentifier;
use Core\Support\Integration\Provider\ProviderCode;
use PHPUnit\Framework\Assert;

/**
 * @return array{0: StartPaymentUseCase, 1: InMemoryPaymentRepository, 2: FakePaymentGateway}
 */
function makeStartPaymentUseCase(): array
{
    $payments = new InMemoryPaymentRepository;
    $router = new FakePaymentProviderRouter;
    $registry = new FakePaymentGatewayRegistry;
    $gateway = new FakePaymentGateway(ProviderCode::fromString('my-coolpay'));
    $registry->register($gateway);

    $useCase = new StartPaymentUseCase($payments, $router, $registry, new SequentialIdentifier);

    return [$useCase, $payments, $gateway];
}

function startCommand(string $idempotencyKey = 'idem-1', ?string $walletRecipientExternalRef = null): StartPaymentCommand
{
    return new StartPaymentCommand(
        projectId: 'project-1',
        channel: PaymentChannel::fromString('mtn_momo'),
        amountMinorUnits: 1_000,
        currency: 'XAF',
        reason: 'Order #42',
        idempotencyKey: $idempotencyKey,
        walletRecipientExternalRef: $walletRecipientExternalRef,
    );
}

it('resolves the provider for the channel and calls its gateway', function (): void {
    [$useCase, $payments, $gateway] = makeStartPaymentUseCase();

    $view = $useCase->start(startCommand());

    expect($gateway->initiated)->toHaveCount(1)
        ->and($gateway->initiated[0]->amountMinorUnits)->toBe(1_000)
        ->and($gateway->initiated[0]->appTransactionRef)->toBe('idem-1')
        ->and($payments->count())->toBe(1)
        ->and($view->status)->toBe(PaymentStatus::Pending);
});

it('persists the provider that actually handled the payment', function (): void {
    [$useCase, $payments] = makeStartPaymentUseCase();

    $view = $useCase->start(startCommand());

    $stored = $payments->find($view->id);
    Assert::assertNotNull($stored);
    expect($stored->providerCode)->toBe('my-coolpay')
        ->and($stored->channel)->toBe('mtn_momo');
});

it('is idempotent: replaying the same key never calls the gateway a second time', function (): void {
    [$useCase, $payments, $gateway] = makeStartPaymentUseCase();

    $first = $useCase->start(startCommand('idem-1'));
    $second = $useCase->start(startCommand('idem-1'));

    expect($gateway->initiated)->toHaveCount(1)
        ->and($second->id)->toBe($first->id)
        ->and($payments->count())->toBe(1);
});

it('scopes idempotency keys to the project', function (): void {
    [$useCase, , $gateway] = makeStartPaymentUseCase();

    $useCase->start(new StartPaymentCommand(
        projectId: 'project-1',
        channel: PaymentChannel::fromString('mtn_momo'),
        amountMinorUnits: 1_000,
        currency: 'XAF',
        reason: 'a',
        idempotencyKey: 'idem-shared',
    ));
    $useCase->start(new StartPaymentCommand(
        projectId: 'project-2',
        channel: PaymentChannel::fromString('mtn_momo'),
        amountMinorUnits: 1_000,
        currency: 'XAF',
        reason: 'b',
        idempotencyKey: 'idem-shared',
    ));

    expect($gateway->initiated)->toHaveCount(2);
});

it('recovers the winning payment when it loses a concurrent creation race', function (): void {
    [$useCase, $payments] = makeStartPaymentUseCase();

    $payments->concurrentWinner = new Payment(
        id: 'winner-id',
        projectId: 'project-1',
        channel: 'mtn_momo',
        providerCode: 'my-coolpay',
        idempotencyKey: 'idem-1',
        providerTransactionRef: 'tx-winner',
        amountMinorUnits: 1_000,
        currency: 'XAF',
        status: PaymentStatus::Pending,
        requiresAction: false,
        actionHint: null,
        paymentUrl: null,
        errorMessage: null,
        returnUrl: null,
        walletRecipientExternalRef: null,
    );

    $view = $useCase->start(startCommand('idem-1'));

    expect($view->id)->toBe('winner-id')
        ->and($payments->count())->toBe(1);
});

it('carries the wallet recipient through to the persisted payment when requested', function (): void {
    [$useCase, $payments] = makeStartPaymentUseCase();

    $view = $useCase->start(startCommand('idem-1', 'customer-ext-ref-1'));

    expect($payments->find($view->id)?->walletRecipientExternalRef)->toBe('customer-ext-ref-1');
});

it('returns receipt fields (requires_action, payment_url) from the gateway', function (): void {
    [$useCase, , $gateway] = makeStartPaymentUseCase();
    $gateway->nextReceipt = new PaymentReceipt(
        transactionRef: 'tx-2',
        status: PaymentStatus::Pending,
        paymentUrl: 'https://pay.example/session/2',
        requiresAction: true,
        actionHint: 'Enter the OTP sent by SMS',
    );

    $view = $useCase->start(startCommand());

    expect($view->paymentUrl)->toBe('https://pay.example/session/2')
        ->and($view->requiresAction)->toBeTrue()
        ->and($view->actionHint)->toBe('Enter the OTP sent by SMS');
});
