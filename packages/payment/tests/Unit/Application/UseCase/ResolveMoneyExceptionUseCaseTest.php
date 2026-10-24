<?php

declare(strict_types=1);

use Core\Payment\Application\UseCase\ResolveMoneyExceptionUseCase;
use Core\Payment\Contracts\ResolveMoneyExceptionCommand;
use Core\Payment\Domain\Exception\MoneyExceptionNotFound;
use Core\Payment\Domain\Exception\MoneyExceptionTransitionRefused;
use Core\Payment\Domain\Model\MoneyException;
use Core\Payment\Domain\Model\MoneyExceptionKind;
use Core\Payment\Domain\Model\MoneyExceptionSeverity;
use Core\Payment\Tests\Unit\Fakes\FixedClock;
use Core\Payment\Tests\Unit\Fakes\InMemoryMoneyExceptionRepository;
use Core\Payment\Tests\Unit\Fakes\PassthroughUnitOfWork;
use Core\Payment\Tests\Unit\Fakes\RecordingSettlementLedger;

/**
 * @return array{0: ResolveMoneyExceptionUseCase, 1: InMemoryMoneyExceptionRepository, 2: RecordingSettlementLedger}
 */
function makeResolver(int $threshold = 50_000): array
{
    $repository = new InMemoryMoneyExceptionRepository;
    $ledger = new RecordingSettlementLedger;

    return [new ResolveMoneyExceptionUseCase(new PassthroughUnitOfWork, $repository, $ledger, new FixedClock, $threshold), $repository, $ledger];
}

function openException(InMemoryMoneyExceptionRepository $repository, int $booked): MoneyException
{
    $exception = MoneyException::open(
        id: 'exc-1', projectId: 'project-1', kind: MoneyExceptionKind::AmountMismatch, severity: MoneyExceptionSeverity::High,
        amount: $booked > 0 ? $booked : 1_000, currency: 'XAF', bookedAmount: $booked, paymentId: 'payment-1', subjectRef: null,
        summary: 'Écart', details: [], now: new DateTimeImmutable('2026-10-24T10:00:00Z'),
    );
    $repository->create($exception);

    return $exception;
}

it('resolves at once and books the money below the four-eyes threshold', function (): void {
    [$resolver, $repository, $ledger] = makeResolver();
    openException($repository, 1_000);

    $view = $resolver->resolve(new ResolveMoneyExceptionCommand('exc-1', 'refunded_outside', 'op-a', 'MOMO-REF-9', 'Remboursé au client par MoMo.'));

    expect($view->status)->toBe('resolved')
        ->and($view->resolution)->toBe('refunded_outside')
        ->and($ledger->resolutions)->toBe([['exceptionId' => 'exc-1', 'amount' => 1_000, 'credit' => 'withdrawal']]);
});

it('waits for a second person at or above the threshold, and books only on approval', function (): void {
    [$resolver, $repository, $ledger] = makeResolver(threshold: 1_000);
    openException($repository, 1_000);

    $proposed = $resolver->resolve(new ResolveMoneyExceptionCommand('exc-1', 'recognized_as_revenue', 'op-a', null, 'Le client a payé un complément volontaire.'));
    expect($proposed->status)->toBe('awaiting_approval')->and($ledger->resolutions)->toBe([]);

    $approved = $resolver->approve('exc-1', 'op-b');
    expect($approved->status)->toBe('resolved')
        ->and($approved->approvedBy)->toBe('op-b')
        ->and($ledger->resolutions)->toBe([['exceptionId' => 'exc-1', 'amount' => 1_000, 'credit' => 'revenue']]);
});

it('never lets the author approve their own resolution', function (): void {
    [$resolver, $repository] = makeResolver(threshold: 1);
    openException($repository, 1_000);
    $resolver->resolve(new ResolveMoneyExceptionCommand('exc-1', 'recognized_as_revenue', 'op-a', null, 'Motif suffisant.'));

    $resolver->approve('exc-1', 'op-a');
})->throws(MoneyExceptionTransitionRefused::class);

it('sends a refused resolution back to open, with nothing booked', function (): void {
    [$resolver, $repository, $ledger] = makeResolver(threshold: 1);
    openException($repository, 1_000);
    $resolver->resolve(new ResolveMoneyExceptionCommand('exc-1', 'recognized_as_revenue', 'op-a', null, 'Motif suffisant.'));

    $view = $resolver->reject('exc-1', 'op-b');

    expect($view->status)->toBe('open')->and($view->resolution)->toBeNull()->and($ledger->resolutions)->toBe([]);
});

it('requires a reference for a refund made by hand', function (): void {
    [$resolver, $repository] = makeResolver();
    openException($repository, 1_000);

    $resolver->resolve(new ResolveMoneyExceptionCommand('exc-1', 'refunded_outside', 'op-a', '  ', 'Remboursé.'));
})->throws(MoneyExceptionTransitionRefused::class);

it('only allows "no movement" when nothing was booked, and books nothing', function (): void {
    [$resolver, $repository, $ledger] = makeResolver();
    openException($repository, 0);

    expect(fn () => $resolver->resolve(new ResolveMoneyExceptionCommand('exc-1', 'recognized_as_revenue', 'op-a', null, 'Essai.')))
        ->toThrow(MoneyExceptionTransitionRefused::class);

    $view = $resolver->resolve(new ResolveMoneyExceptionCommand('exc-1', 'no_action', 'op-a', null, 'Devise inconnue, traitée hors plateforme.'));
    expect($view->status)->toBe('resolved')->and($ledger->resolutions)->toBe([]);
});

it('refuses to resolve twice', function (): void {
    [$resolver, $repository] = makeResolver();
    openException($repository, 1_000);
    $resolver->resolve(new ResolveMoneyExceptionCommand('exc-1', 'refunded_outside', 'op-a', 'REF-1', 'Remboursé.'));

    $resolver->resolve(new ResolveMoneyExceptionCommand('exc-1', 'recognized_as_revenue', 'op-b', null, 'Encore.'));
})->throws(MoneyExceptionTransitionRefused::class);

it('reports an unknown exception', function (): void {
    [$resolver] = makeResolver();

    $resolver->approve('nope', 'op-b');
})->throws(MoneyExceptionNotFound::class);
