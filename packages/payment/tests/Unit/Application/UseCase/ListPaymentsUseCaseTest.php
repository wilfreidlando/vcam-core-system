<?php

declare(strict_types=1);

use Core\Payment\Application\UseCase\ListPaymentsUseCase;
use Core\Payment\Contracts\ListPaymentsCommand;
use Core\Payment\Domain\Model\Payment;
use Core\Payment\PaymentStatus;
use Core\Payment\Tests\Unit\Fakes\InMemoryPaymentRepository;

function listablePayment(string $id, string $projectId, PaymentStatus $status = PaymentStatus::Succeeded): Payment
{
    return new Payment(
        id: $id,
        projectId: $projectId,
        channel: 'mtn_momo',
        providerCode: 'fake-provider',
        idempotencyKey: 'idem-'.$id,
        providerTransactionRef: 'tx-'.$id,
        amount: 1_000,
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

it('lists only the requesting project\'s payments', function (): void {
    $payments = new InMemoryPaymentRepository;
    $payments->create(listablePayment('p1', 'project-a'));
    $payments->create(listablePayment('p2', 'project-b'));

    $useCase = new ListPaymentsUseCase($payments);
    $page = $useCase->list(new ListPaymentsCommand('project-a'));

    expect($page->total)->toBe(1)
        ->and($page->items[0]->id)->toBe('p1');
});

it('filters by canonical status', function (): void {
    $payments = new InMemoryPaymentRepository;
    $payments->create(listablePayment('p1', 'project-a', PaymentStatus::Succeeded));
    $payments->create(listablePayment('p2', 'project-a', PaymentStatus::Failed));

    $useCase = new ListPaymentsUseCase($payments);
    $page = $useCase->list(new ListPaymentsCommand('project-a', status: 'failed'));

    expect($page->total)->toBe(1)
        ->and($page->items[0]->id)->toBe('p2');
});

it('treats an unrecognised status string as no filter', function (): void {
    $payments = new InMemoryPaymentRepository;
    $payments->create(listablePayment('p1', 'project-a'));

    $useCase = new ListPaymentsUseCase($payments);
    $page = $useCase->list(new ListPaymentsCommand('project-a', status: 'not-a-real-status'));

    expect($page->total)->toBe(1);
});
