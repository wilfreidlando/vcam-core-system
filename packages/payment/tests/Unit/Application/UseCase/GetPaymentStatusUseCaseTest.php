<?php

declare(strict_types=1);

use Core\Payment\Application\UseCase\GetPaymentStatusUseCase;
use Core\Payment\Contracts\GetPaymentStatusCommand;
use Core\Payment\Domain\Exception\PaymentNotFound;
use Core\Payment\Domain\Model\Payment;
use Core\Payment\PaymentStatus;
use Core\Payment\Tests\Unit\Fakes\InMemoryPaymentRepository;

function seedPayment(InMemoryPaymentRepository $repo, string $id = 'payment-1', string $projectId = 'project-1'): Payment
{
    $payment = new Payment(
        id: $id,
        projectId: $projectId,
        channel: 'mtn_momo',
        providerCode: 'my-coolpay',
        idempotencyKey: 'idem-1',
        providerTransactionRef: 'tx-1',
        amountMinorUnits: 1_000,
        currency: 'XAF',
        status: PaymentStatus::Succeeded,
        requiresAction: false,
        actionHint: null,
        paymentUrl: null,
        errorMessage: null,
        returnUrl: null,
        walletRecipientExternalRef: null,
    );
    $repo->create($payment);

    return $payment;
}

it('returns the view for a payment owned by the requesting project', function (): void {
    $payments = new InMemoryPaymentRepository;
    seedPayment($payments);

    $useCase = new GetPaymentStatusUseCase($payments);
    $view = $useCase->get(new GetPaymentStatusCommand('payment-1', 'project-1'));

    expect($view->id)->toBe('payment-1')
        ->and($view->status)->toBe(PaymentStatus::Succeeded);
});

it('rejects an unknown payment id', function (): void {
    $useCase = new GetPaymentStatusUseCase(new InMemoryPaymentRepository);

    $useCase->get(new GetPaymentStatusCommand('unknown', 'project-1'));
})->throws(PaymentNotFound::class);

it('rejects a payment that belongs to another project, with the same exception as not-found', function (): void {
    $payments = new InMemoryPaymentRepository;
    seedPayment($payments, 'payment-1', 'project-1');

    $useCase = new GetPaymentStatusUseCase($payments);

    $useCase->get(new GetPaymentStatusCommand('payment-1', 'someone-elses-project'));
})->throws(PaymentNotFound::class);
