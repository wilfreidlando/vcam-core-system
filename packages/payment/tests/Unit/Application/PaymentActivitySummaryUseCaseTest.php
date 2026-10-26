<?php

declare(strict_types=1);

use Core\Payment\Application\UseCase\PaymentActivitySummaryUseCase;
use Core\Payment\Contracts\PaymentActivitySummaryWindow;
use Core\Payment\Domain\Model\Payment;
use Core\Payment\PaymentStatus;
use Core\Payment\Tests\Unit\Fakes\FixedClock;
use Core\Payment\Tests\Unit\Fakes\InMemoryPaymentRepository;
use Core\Support\Access\AccessScope;

/**
 * ADR-0069 §2 — the dashboard's payment activity summary must only count
 * the payments of the projects the console account may see.
 */
function paymentForActivitySummary(string $id, string $projectId, PaymentStatus $status): Payment
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

it('summarises every payment for a platform scope', function (): void {
    $payments = new InMemoryPaymentRepository;
    $payments->create(paymentForActivitySummary('p1', 'project-1', PaymentStatus::Succeeded));
    $payments->create(paymentForActivitySummary('p2', 'project-2', PaymentStatus::Pending));

    $useCase = new PaymentActivitySummaryUseCase(payments: $payments, clock: new FixedClock);

    $summary = $useCase->summarize(new PaymentActivitySummaryWindow(24), AccessScope::platform());

    expect($summary->total)->toBe(2)
        ->and($summary->succeeded)->toBe(1);
});

it('summarises only the payments of the projects in scope', function (): void {
    $payments = new InMemoryPaymentRepository;
    $payments->create(paymentForActivitySummary('p1', 'project-1', PaymentStatus::Succeeded));
    $payments->create(paymentForActivitySummary('p2', 'project-2', PaymentStatus::Succeeded));

    $useCase = new PaymentActivitySummaryUseCase(payments: $payments, clock: new FixedClock);

    $summary = $useCase->summarize(new PaymentActivitySummaryWindow(24), AccessScope::forProject('project-1'));

    expect($summary->total)->toBe(1)
        ->and($summary->succeeded)->toBe(1);
});

it('summarises nothing when the scope holds no project', function (): void {
    $payments = new InMemoryPaymentRepository;
    $payments->create(paymentForActivitySummary('p1', 'project-1', PaymentStatus::Succeeded));

    $useCase = new PaymentActivitySummaryUseCase(payments: $payments, clock: new FixedClock);

    $summary = $useCase->summarize(new PaymentActivitySummaryWindow(24), AccessScope::none());

    expect($summary->total)->toBe(0)
        ->and($summary->succeeded)->toBe(0);
});
