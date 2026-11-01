<?php

declare(strict_types=1);

use Core\Payment\Application\UseCase\CountStuckPaymentsUseCase;
use Core\Payment\Domain\Model\Payment;
use Core\Payment\PaymentStatus;
use Core\Payment\Tests\Unit\Fakes\FixedClock;
use Core\Payment\Tests\Unit\Fakes\InMemoryPaymentRepository;
use Core\Support\Access\AccessScope;

/**
 * ADR-0069 §2 — the dashboard's "stuck payments" counter must only count
 * the payments of the projects the console account may see.
 */
function paymentForStuckCount(string $id, string $projectId, PaymentStatus $status = PaymentStatus::Processing): Payment
{
    return new Payment(
        id: $id,
        projectId: $projectId,
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

it('counts every stuck payment for a platform scope', function (): void {
    $payments = new InMemoryPaymentRepository;
    $payments->create(paymentForStuckCount('p1', 'project-1'));
    $payments->create(paymentForStuckCount('p2', 'project-2'));

    $useCase = new CountStuckPaymentsUseCase(
        payments: $payments,
        clock: new FixedClock,
        stuckAfterMinutes: 15,
    );

    expect($useCase->count(AccessScope::platform()))->toBe(2);
});

it('counts only the stuck payments of the projects in scope', function (): void {
    $payments = new InMemoryPaymentRepository;
    $payments->create(paymentForStuckCount('p1', 'project-1'));
    $payments->create(paymentForStuckCount('p2', 'project-2'));

    $useCase = new CountStuckPaymentsUseCase(
        payments: $payments,
        clock: new FixedClock,
        stuckAfterMinutes: 15,
    );

    expect($useCase->count(AccessScope::forProject('project-1')))->toBe(1);
});

it('counts nothing when the scope holds no project', function (): void {
    $payments = new InMemoryPaymentRepository;
    $payments->create(paymentForStuckCount('p1', 'project-1'));

    $useCase = new CountStuckPaymentsUseCase(
        payments: $payments,
        clock: new FixedClock,
        stuckAfterMinutes: 15,
    );

    expect($useCase->count(AccessScope::none()))->toBe(0);
});
