<?php

declare(strict_types=1);

use Core\Payment\Application\UseCase\ListStuckPaymentsUseCase;
use Core\Payment\Contracts\ListStuckPaymentsCommand;
use Core\Payment\Domain\Model\Payment;
use Core\Payment\PaymentStatus;
use Core\Payment\Tests\Unit\Fakes\FixedClock;
use Core\Payment\Tests\Unit\Fakes\InMemoryPaymentRepository;

function stuckPaymentForListing(string $id, PaymentStatus $status = PaymentStatus::Processing): Payment
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

it('returns an empty list when no payment is stuck', function (): void {
    $useCase = new ListStuckPaymentsUseCase(
        payments: new InMemoryPaymentRepository,
        clock: new FixedClock,
        stuckAfterMinutes: 15,
    );

    $views = $useCase->list(new ListStuckPaymentsCommand(limit: 100));

    expect($views)->toBe([]);
});

it('maps each stuck payment to a narrow StuckPaymentView', function (): void {
    $payments = new InMemoryPaymentRepository;
    $payments->create(stuckPaymentForListing('p1', PaymentStatus::Processing));

    $useCase = new ListStuckPaymentsUseCase(
        payments: $payments,
        clock: new FixedClock,
        stuckAfterMinutes: 15,
    );

    $views = $useCase->list(new ListStuckPaymentsCommand(limit: 100));

    expect($views)->toHaveCount(1);

    $view = $views[0];
    expect($view->id)->toBe('p1')
        ->and($view->projectId)->toBe('project-1')
        ->and($view->channel)->toBe('mtn_momo')
        ->and($view->status)->toBe('processing')
        ->and($view->amount)->toBe(1000)
        ->and($view->currency)->toBe('XAF');
});

it('never returns a terminal-status payment', function (): void {
    $payments = new InMemoryPaymentRepository;
    $payments->create(stuckPaymentForListing('p1', PaymentStatus::Succeeded));

    $useCase = new ListStuckPaymentsUseCase(
        payments: $payments,
        clock: new FixedClock,
        stuckAfterMinutes: 15,
    );

    $views = $useCase->list(new ListStuckPaymentsCommand(limit: 100));

    expect($views)->toBe([]);
});

it('honours the limit passed in the command', function (): void {
    $payments = new InMemoryPaymentRepository;
    $payments->create(stuckPaymentForListing('p1'));
    $payments->create(stuckPaymentForListing('p2'));
    $payments->create(stuckPaymentForListing('p3'));

    $useCase = new ListStuckPaymentsUseCase(
        payments: $payments,
        clock: new FixedClock,
        stuckAfterMinutes: 15,
    );

    $views = $useCase->list(new ListStuckPaymentsCommand(limit: 2));

    expect($views)->toHaveCount(2);
});
