<?php

declare(strict_types=1);

use Core\Payment\Application\UseCase\ResolveMoneyExceptionUseCase;
use Core\Payment\Contracts\MoneyExceptionPoolInsufficient;
use Core\Payment\Contracts\ResolveMoneyExceptionCommand;
use Core\Payment\Domain\Exception\MoneyExceptionNotFound;
use Core\Payment\Domain\Exception\MoneyExceptionTransitionRefused;
use Core\Payment\Domain\Model\MoneyException;
use Core\Payment\Domain\Model\MoneyExceptionKind;
use Core\Payment\Domain\Model\MoneyExceptionResolution;
use Core\Payment\Domain\Model\MoneyExceptionSeverity;
use Core\Payment\Tests\Unit\Fakes\FakeCreditWallet;
use Core\Payment\Tests\Unit\Fakes\FakeMoneyPoolLock;
use Core\Payment\Tests\Unit\Fakes\FakePartyFinder;
use Core\Payment\Tests\Unit\Fakes\FakeTrialBalance;
use Core\Payment\Tests\Unit\Fakes\FixedClock;
use Core\Payment\Tests\Unit\Fakes\InMemoryMoneyExceptionRepository;
use Core\Payment\Tests\Unit\Fakes\PassthroughUnitOfWork;
use Core\Payment\Tests\Unit\Fakes\RecordingSettlementLedger;
use Core\Support\Access\AccessScope;

/**
 * @return array{0: ResolveMoneyExceptionUseCase, 1: InMemoryMoneyExceptionRepository, 2: RecordingSettlementLedger, 3: FakeCreditWallet, 4: FakePartyFinder, 5: FakeTrialBalance, 6: FakeMoneyPoolLock}
 */
function makeResolver(int $threshold = 50_000): array
{
    $repository = new InMemoryMoneyExceptionRepository;
    $ledger = new RecordingSettlementLedger;
    $creditWallet = new FakeCreditWallet;
    $partyFinder = new FakePartyFinder;
    $trialBalance = new FakeTrialBalance;
    $poolLock = new FakeMoneyPoolLock;

    return [
        new ResolveMoneyExceptionUseCase(new PassthroughUnitOfWork, $repository, $ledger, new FixedClock, $threshold, $creditWallet, $partyFinder, $trialBalance, $poolLock),
        $repository,
        $ledger,
        $creditWallet,
        $partyFinder,
        $trialBalance,
        $poolLock,
    ];
}

/**
 * Opens an exception in the queue.
 *
 * `$pooled` says whether the exception pool (`payment_exceptions`)
 * actually holds the booked amount: an exception whose money was never
 * written to the ledger holds nothing there.
 */
function openException(
    InMemoryMoneyExceptionRepository $repository,
    FakeTrialBalance $trialBalance,
    int $booked,
    ?string $subjectRef = null,
    bool $pooled = true,
): MoneyException {
    $exception = MoneyException::open(
        id: 'exc-1', projectId: 'project-1', kind: MoneyExceptionKind::AmountMismatch, severity: MoneyExceptionSeverity::High,
        amount: $booked > 0 ? $booked : 1_000, currency: 'XAF', bookedAmount: $booked, paymentId: 'payment-1', subjectRef: $subjectRef,
        summary: 'Écart', details: [], now: new DateTimeImmutable('2026-10-24T10:00:00Z'),
    );
    $repository->create($exception);

    if ($pooled) {
        $trialBalance->creditPool('XAF', $booked);
    }

    return $exception;
}

it('resolves at once and books the money below the four-eyes threshold', function (): void {
    [$resolver, $repository, $ledger, , , $trialBalance] = makeResolver();
    openException($repository, $trialBalance, 1_000);

    $view = $resolver->resolve(new ResolveMoneyExceptionCommand('exc-1', 'refunded_outside', 'op-a', 'MOMO-REF-9', 'Remboursé au client par MoMo.'), AccessScope::platform());

    expect($view->status)->toBe('resolved')
        ->and($view->resolution)->toBe('refunded_outside')
        ->and($ledger->resolutions)->toBe([['exceptionId' => 'exc-1', 'amount' => 1_000, 'credit' => 'withdrawal']]);
});

it('waits for a second person at or above the threshold, and books only on approval', function (): void {
    [$resolver, $repository, $ledger, , , $trialBalance] = makeResolver(threshold: 1_000);
    openException($repository, $trialBalance, 1_000);

    $proposed = $resolver->resolve(new ResolveMoneyExceptionCommand('exc-1', 'recognized_as_revenue', 'op-a', null, 'Le client a payé un complément volontaire.'), AccessScope::platform());
    expect($proposed->status)->toBe('awaiting_approval')->and($ledger->resolutions)->toBe([]);

    $approved = $resolver->approve('exc-1', 'op-b', AccessScope::platform());
    expect($approved->status)->toBe('resolved')
        ->and($approved->approvedBy)->toBe('op-b')
        ->and($ledger->resolutions)->toBe([['exceptionId' => 'exc-1', 'amount' => 1_000, 'credit' => 'revenue']]);
});

it('never lets the author approve their own resolution', function (): void {
    [$resolver, $repository, , , , $trialBalance] = makeResolver(threshold: 1);
    openException($repository, $trialBalance, 1_000);
    $resolver->resolve(new ResolveMoneyExceptionCommand('exc-1', 'recognized_as_revenue', 'op-a', null, 'Motif suffisant.'), AccessScope::platform());

    $resolver->approve('exc-1', 'op-a', AccessScope::platform());
})->throws(MoneyExceptionTransitionRefused::class);

it('sends a refused resolution back to open, with nothing booked', function (): void {
    [$resolver, $repository, $ledger, , , $trialBalance] = makeResolver(threshold: 1);
    openException($repository, $trialBalance, 1_000);
    $resolver->resolve(new ResolveMoneyExceptionCommand('exc-1', 'recognized_as_revenue', 'op-a', null, 'Motif suffisant.'), AccessScope::platform());

    $view = $resolver->reject('exc-1', 'op-b', AccessScope::platform());

    expect($view->status)->toBe('open')->and($view->resolution)->toBeNull()->and($ledger->resolutions)->toBe([]);
});

it('requires a reference for a refund made by hand', function (): void {
    [$resolver, $repository, , , , $trialBalance] = makeResolver();
    openException($repository, $trialBalance, 1_000);

    $resolver->resolve(new ResolveMoneyExceptionCommand('exc-1', 'refunded_outside', 'op-a', '  ', 'Remboursé.'), AccessScope::platform());
})->throws(MoneyExceptionTransitionRefused::class);

it('only allows "no movement" when nothing was booked, and books nothing', function (): void {
    [$resolver, $repository, $ledger, , , $trialBalance] = makeResolver();
    openException($repository, $trialBalance, 0);

    expect(fn () => $resolver->resolve(new ResolveMoneyExceptionCommand('exc-1', 'recognized_as_revenue', 'op-a', null, 'Essai.'), AccessScope::platform()))
        ->toThrow(MoneyExceptionTransitionRefused::class);

    $view = $resolver->resolve(new ResolveMoneyExceptionCommand('exc-1', 'no_action', 'op-a', null, 'Devise inconnue, traitée hors plateforme.'), AccessScope::platform());
    expect($view->status)->toBe('resolved')->and($ledger->resolutions)->toBe([]);
});

it('refuses to resolve twice', function (): void {
    [$resolver, $repository, , , , $trialBalance] = makeResolver();
    openException($repository, $trialBalance, 1_000);
    $resolver->resolve(new ResolveMoneyExceptionCommand('exc-1', 'refunded_outside', 'op-a', 'REF-1', 'Remboursé.'), AccessScope::platform());

    $resolver->resolve(new ResolveMoneyExceptionCommand('exc-1', 'recognized_as_revenue', 'op-b', null, 'Encore.'), AccessScope::platform());
})->throws(MoneyExceptionTransitionRefused::class);

it('reports an unknown exception', function (): void {
    [$resolver] = makeResolver();

    $resolver->approve('nope', 'op-b', AccessScope::platform());
})->throws(MoneyExceptionNotFound::class);

it('never settles an exception of a project outside the caller scope (ADR-0069 §2)', function (): void {
    [$resolver, $repository, $ledger, , , $trialBalance] = makeResolver();
    openException($repository, $trialBalance, 10_000);

    expect(fn () => $resolver->resolve(
        new ResolveMoneyExceptionCommand('exc-1', 'refunded_outside', 'op-a', 'REF-1', 'Remboursé.'),
        AccessScope::forProject('another-project'),
    ))->toThrow(MoneyExceptionNotFound::class);

    expect(fn () => $resolver->approve('exc-1', 'op-b', AccessScope::none()))->toThrow(MoneyExceptionNotFound::class)
        ->and(fn () => $resolver->reject('exc-1', 'op-b', AccessScope::none()))->toThrow(MoneyExceptionNotFound::class)
        ->and($repository->find('exc-1')?->status->value)->toBe('open')
        ->and($ledger->resolutions)->toBe([]);
});

it('offers to impute the money to a wallet only when the exception names a party', function (): void {
    $withParty = MoneyException::open(
        id: 'exc-with-party', projectId: 'project-1', kind: MoneyExceptionKind::AmountMismatch, severity: MoneyExceptionSeverity::High,
        amount: 1_000, currency: 'XAF', bookedAmount: 1_000, paymentId: 'payment-1', subjectRef: 'customer-ext-1',
        summary: 'Écart', details: [], now: new DateTimeImmutable('2026-10-24T10:00:00Z'),
    );
    $withoutParty = MoneyException::open(
        id: 'exc-without-party', projectId: 'project-1', kind: MoneyExceptionKind::AmountMismatch, severity: MoneyExceptionSeverity::High,
        amount: 1_000, currency: 'XAF', bookedAmount: 1_000, paymentId: 'payment-2', subjectRef: null,
        summary: 'Écart', details: [], now: new DateTimeImmutable('2026-10-24T10:00:00Z'),
    );

    expect($withParty->allowedResolutions())->toContain(MoneyExceptionResolution::AllocatedToWallet)
        ->and($withoutParty->allowedResolutions())->not->toContain(MoneyExceptionResolution::AllocatedToWallet);
});

it('imputes the money to the wallet of the party, and books nothing in the ledger (ADR-0077 §4)', function (): void {
    [$resolver, $repository, $ledger, $creditWallet, $partyFinder, $trialBalance] = makeResolver();
    openException($repository, $trialBalance, 1_000, 'customer-ext-1');

    $view = $resolver->resolve(new ResolveMoneyExceptionCommand('exc-1', 'allocated_to_wallet', 'op-a', null, 'Le trop-perçu revient au client.'), AccessScope::platform());

    expect($view->status)->toBe('resolved')
        ->and($view->resolution)->toBe('allocated_to_wallet')
        // The wallet module posts the pair, the ledger books nothing here.
        ->and($ledger->resolutions)->toBe([])
        ->and($partyFinder->calls)->toHaveCount(1)
        ->and($partyFinder->calls[0]->externalRef)->toBe('customer-ext-1')
        ->and($creditWallet->credited)->toHaveCount(1)
        ->and($creditWallet->credited[0]->amountMinor)->toBe(1_000)
        ->and($creditWallet->credited[0]->currency)->toBe('XAF')
        ->and($creditWallet->credited[0]->idempotencyKey)->toBe('money_exception:exc-1:wallet')
        // The money comes out of the exception pool, not from a provider's account.
        ->and($creditWallet->credited[0]->origin?->source->value)->toBe('payment_exceptions')
        ->and($creditWallet->credited[0]->origin?->accountReference())->toBe('');
});

it('credits the wallet only once the second person has approved', function (): void {
    [$resolver, $repository, , $creditWallet, , $trialBalance] = makeResolver(threshold: 1_000);
    openException($repository, $trialBalance, 1_000, 'customer-ext-1');

    $proposed = $resolver->resolve(new ResolveMoneyExceptionCommand('exc-1', 'allocated_to_wallet', 'op-a', null, 'Le trop-perçu revient au client.'), AccessScope::platform());

    expect($proposed->status)->toBe('awaiting_approval')
        ->and($creditWallet->credited)->toBe([]);

    $approved = $resolver->approve('exc-1', 'op-b', AccessScope::platform());

    expect($approved->status)->toBe('resolved')
        ->and($approved->approvedBy)->toBe('op-b')
        ->and($creditWallet->credited)->toHaveCount(1);
});

it('never lets the author approve their own wallet allocation', function (): void {
    [$resolver, $repository, , $creditWallet, , $trialBalance] = makeResolver(threshold: 1);
    openException($repository, $trialBalance, 1_000, 'customer-ext-1');
    $resolver->resolve(new ResolveMoneyExceptionCommand('exc-1', 'allocated_to_wallet', 'op-a', null, 'Le trop-perçu revient au client.'), AccessScope::platform());

    expect(fn () => $resolver->approve('exc-1', 'op-a', AccessScope::platform()))->toThrow(MoneyExceptionTransitionRefused::class)
        ->and($creditWallet->credited)->toBe([]);
});

it('credits no wallet when the refused allocation goes back to open', function (): void {
    [$resolver, $repository, , $creditWallet, , $trialBalance] = makeResolver(threshold: 1);
    openException($repository, $trialBalance, 1_000, 'customer-ext-1');
    $resolver->resolve(new ResolveMoneyExceptionCommand('exc-1', 'allocated_to_wallet', 'op-a', null, 'Le trop-perçu revient au client.'), AccessScope::platform());

    $view = $resolver->reject('exc-1', 'op-b', AccessScope::platform());

    expect($view->status)->toBe('open')->and($creditWallet->credited)->toBe([]);
});

/**
 * ADR-0077 §4 — the pool must hold the money the resolution moves.
 *
 * An exception whose money was never written to the grand livre holds
 * nothing on `payment_exceptions`: moving its amount would take money
 * that was never collected.
 */
it('refuses to impute to a wallet money the exception pool does not hold', function (): void {
    [$resolver, $repository, $ledger, $creditWallet, , $trialBalance] = makeResolver();
    openException($repository, $trialBalance, 5_000, 'customer-ext-1', pooled: false);

    expect(fn () => $resolver->resolve(
        new ResolveMoneyExceptionCommand('exc-1', 'allocated_to_wallet', 'op-a', null, 'Le trop-perçu revient au client.'),
        AccessScope::platform(),
    ))->toThrow(MoneyExceptionPoolInsufficient::class);

    expect($creditWallet->credited)->toBe([])
        ->and($ledger->resolutions)->toBe([])
        ->and($repository->find('exc-1')?->status->value)->toBe('open')
        ->and($repository->find('exc-1')?->resolution)->toBeNull();
});

it('refuses to refund money the exception pool does not hold', function (): void {
    [$resolver, $repository, $ledger, , , $trialBalance] = makeResolver();
    openException($repository, $trialBalance, 5_000, pooled: false);

    expect(fn () => $resolver->resolve(
        new ResolveMoneyExceptionCommand('exc-1', 'refunded_outside', 'op-a', 'MOMO-REF-9', 'Remboursé au client par MoMo.'),
        AccessScope::platform(),
    ))->toThrow(MoneyExceptionPoolInsufficient::class);

    expect($ledger->resolutions)->toBe([])
        ->and($repository->find('exc-1')?->status->value)->toBe('open');
});

it('refuses to recognise as revenue money the exception pool does not hold', function (): void {
    [$resolver, $repository, $ledger, , , $trialBalance] = makeResolver();
    openException($repository, $trialBalance, 5_000, pooled: false);

    expect(fn () => $resolver->resolve(
        new ResolveMoneyExceptionCommand('exc-1', 'recognized_as_revenue', 'op-a', null, 'Le client a payé un complément.'),
        AccessScope::platform(),
    ))->toThrow(MoneyExceptionPoolInsufficient::class);

    expect($ledger->resolutions)->toBe([]);
});

it('refuses when the pool holds less than the exception books', function (): void {
    [$resolver, $repository, , $creditWallet, , $trialBalance] = makeResolver();
    openException($repository, $trialBalance, 5_000, 'customer-ext-1', pooled: false);
    $trialBalance->creditPool('XAF', 4_999);

    expect(fn () => $resolver->resolve(
        new ResolveMoneyExceptionCommand('exc-1', 'allocated_to_wallet', 'op-a', null, 'Le trop-perçu revient au client.'),
        AccessScope::platform(),
    ))->toThrow(MoneyExceptionPoolInsufficient::class);

    expect($creditWallet->credited)->toBe([]);
});

/**
 * ADR-0076 §2 — the pool (`payment_exceptions`) is ONE account per
 * project and per currency, NOT a reserve held per exception. Two
 * exceptions on the same pool share it: the first one resolved spends
 * it, and the second one can then be refused even though the pool would
 * have covered the total of the two.
 *
 * That behaviour is correct — but the refusal has to SAY it, or the
 * operator reads a spent pool as missing money.
 */
it('explains that the exception pool is one account per project and currency, not a reserve per exception', function (): void {
    [$resolver, $repository, , , , $trialBalance] = makeResolver();
    openException($repository, $trialBalance, 5_000, pooled: false);

    try {
        $resolver->resolve(
            new ResolveMoneyExceptionCommand('exc-1', 'refunded_outside', 'op-a', 'MOMO-REF-9', 'Remboursé au client par MoMo.'),
            AccessScope::platform(),
        );
        throw new LogicException('The resolution must be refused when the pool holds nothing.');
    } catch (MoneyExceptionPoolInsufficient $e) {
        expect($e->getMessage())->toContain('compte unique par projet et par devise')
            ->and($e->getMessage())->toContain('réserve par exception');
    }
});

it('locks the pool of the project and the currency before spending it', function (): void {
    [$resolver, $repository, , , , $trialBalance, $poolLock] = makeResolver();
    openException($repository, $trialBalance, 1_000, 'customer-ext-1');

    $resolver->resolve(new ResolveMoneyExceptionCommand('exc-1', 'allocated_to_wallet', 'op-a', null, 'Le trop-perçu revient au client.'), AccessScope::platform());

    expect($poolLock->locked)->toBe([['projectId' => 'project-1', 'currency' => 'XAF']]);
});
