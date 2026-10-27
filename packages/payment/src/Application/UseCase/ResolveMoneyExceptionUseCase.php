<?php

declare(strict_types=1);

namespace Core\Payment\Application\UseCase;

use Core\Ledger\Contracts\TrialBalance;
use Core\Parties\Contracts\FindOrCreatePartyCommand;
use Core\Parties\Contracts\PartyFinder;
use Core\Payment\Application\Support\MoneyExceptionViews;
use Core\Payment\Contracts\MoneyExceptionPoolInsufficient;
use Core\Payment\Contracts\MoneyExceptionView;
use Core\Payment\Contracts\ResolveMoneyException;
use Core\Payment\Contracts\ResolveMoneyExceptionCommand;
use Core\Payment\Domain\Exception\MoneyExceptionNotFound;
use Core\Payment\Domain\Exception\MoneyExceptionTransitionRefused;
use Core\Payment\Domain\Model\MoneyException;
use Core\Payment\Domain\Model\MoneyExceptionResolution;
use Core\Payment\Domain\Model\MoneyExceptionStatus;
use Core\Payment\Domain\Model\SettlementAccount;
use Core\Payment\Domain\Port\MoneyExceptionRepository;
use Core\Payment\Domain\Port\MoneyPoolLock;
use Core\Payment\Domain\Port\SettlementLedger;
use Core\Support\Access\AccessScope;
use Core\Support\Clock;
use Core\Support\UnitOfWork;
use Core\Wallet\Contracts\CreditWallet;
use Core\Wallet\Contracts\CreditWalletCommand;
use Core\Wallet\Contracts\WalletCreditOrigin;
use Core\Wallet\Contracts\WalletCreditSource;

/**
 * ADR-0077 §4, ADR-0081 S-15 — the state change and its ledger movement
 * share one transaction, under a row lock: two people resolving the same
 * exception at once cannot both book it.
 *
 * ADR-0077 §4 — and the money a resolution moves must EXIST: the pool
 * `payment_exceptions` is read before it is debited, under the pool's
 * own lock (ADR-0078 §2, invariant 4 — no value created).
 *
 * The pool is ONE account per project and per currency (ADR-0076 §2),
 * NOT a reserve held per exception: two exceptions on the same pool
 * share its balance. The first one resolved spends it, and the second
 * one can then be refused even when the pool would have covered the
 * total. The refusal (`MoneyExceptionPoolInsufficient`) says so, so the
 * operator sees a spent pool rather than missing money.
 */
final readonly class ResolveMoneyExceptionUseCase implements ResolveMoneyException
{
    public function __construct(
        private UnitOfWork $unitOfWork,
        private MoneyExceptionRepository $exceptions,
        private SettlementLedger $ledger,
        private Clock $clock,
        /** At or above this booked amount (minor units), a second person must approve. */
        private int $approvalThresholdMinor,
        private CreditWallet $creditWallet,
        private PartyFinder $partyFinder,
        private TrialBalance $trialBalance,
        private MoneyPoolLock $poolLock,
    ) {}

    public function resolve(ResolveMoneyExceptionCommand $command, AccessScope $scope): MoneyExceptionView
    {
        $resolution = MoneyExceptionResolution::tryFrom($command->resolution)
            ?? throw MoneyExceptionTransitionRefused::resolutionNotAllowed($command->exceptionId, $command->resolution);

        return $this->unitOfWork->transactional(function () use ($command, $resolution, $scope): MoneyExceptionView {
            $exception = $this->locked($command->exceptionId, $scope);

            $resolved = $exception->propose(
                resolution: $resolution,
                operatorId: $command->operatorId,
                reference: $command->reference,
                note: $command->note,
                requiresApproval: $exception->bookedAmount > 0 && $exception->bookedAmount >= $this->approvalThresholdMinor,
                now: $this->clock->now(),
            );

            return $this->persist($resolved);
        });
    }

    public function approve(string $exceptionId, string $approverOperatorId, AccessScope $scope): MoneyExceptionView
    {
        return $this->unitOfWork->transactional(function () use ($exceptionId, $approverOperatorId, $scope): MoneyExceptionView {
            return $this->persist($this->locked($exceptionId, $scope)->approve($approverOperatorId, $this->clock->now()));
        });
    }

    public function reject(string $exceptionId, string $approverOperatorId, AccessScope $scope): MoneyExceptionView
    {
        return $this->unitOfWork->transactional(function () use ($exceptionId, $approverOperatorId, $scope): MoneyExceptionView {
            return $this->persist($this->locked($exceptionId, $scope)->reject($approverOperatorId));
        });
    }

    /**
     * The exception, locked — and only if the caller may act on its
     * project (ADR-0069 §2). The check runs on the locked row, so it
     * cannot be raced by a concurrent read of a stale copy.
     */
    private function locked(string $exceptionId, AccessScope $scope): MoneyException
    {
        $exception = $this->exceptions->findForUpdate($exceptionId);

        if ($exception === null || ! $scope->allowsProject($exception->projectId)) {
            throw MoneyExceptionNotFound::withId($exceptionId);
        }

        return $exception;
    }

    private function persist(MoneyException $exception): MoneyExceptionView
    {
        // Before anything is written: the money the resolution moves must
        // be there (ADR-0077 §4). A refusal leaves the exception exactly
        // as it was.
        if ($this->movesMoney($exception)) {
            $this->guardPool($exception);
        }

        $this->exceptions->save($exception);

        if ($exception->resolution === MoneyExceptionResolution::AllocatedToWallet) {
            // The wallet module posts the pair (`payment_exceptions` →
            // `wallet:<partie>`) — it is the only one that knows the
            // wallet's ledger account (ADR-0077 §4).
            $this->creditWalletFor($exception);
        } else {
            $this->ledger->recordExceptionResolution($exception);
        }

        return MoneyExceptionViews::from($exception);
    }

    /**
     * Whether this decision moves money out of `payment_exceptions`.
     *
     * Every resolution that books an account does (`refunded_outside`,
     * `recognized_as_revenue`), and so does the one that books a wallet:
     * the wallet module posts the pair itself, but the money still
     * leaves the pool. `no_action` moves nothing — there is nothing on
     * the pool for it.
     */
    private function movesMoney(MoneyException $exception): bool
    {
        return $exception->status === MoneyExceptionStatus::Resolved
            && $exception->bookedAmount > 0
            && $exception->resolution !== null
            && $exception->resolution !== MoneyExceptionResolution::NoAction;
    }

    /**
     * ADR-0077 §4 — the pool must actually hold the money the resolution
     * moves.
     *
     * The pool (`payment_exceptions`) is credited when money is collected
     * and cannot be allocated, debited when it is settled. Reading its
     * balance and spending it in the SAME transaction, under the pool's
     * lock, is what keeps a resolution from taking money that was never
     * collected.
     *
     * The pool is one account per project and per currency (ADR-0076 §2),
     * not a reserve per exception: two exceptions on the same pool share
     * it, so the second one may be refused even when the pool would have
     * covered the total of the two.
     */
    private function guardPool(MoneyException $exception): void
    {
        // Serialise FIRST: the balance read below must see what a
        // concurrent resolution of another exception leaves behind.
        $this->poolLock->lockPool($exception->projectId, $exception->currency);

        $available = $this->poolBalance($exception->projectId, $exception->currency);

        if ($available < $exception->bookedAmount) {
            throw MoneyExceptionPoolInsufficient::forException(
                $exception->id,
                $exception->currency,
                $available,
                $exception->bookedAmount,
            );
        }
    }

    /**
     * What the pool holds for this project and currency, in minor units.
     *
     * The pool is CREDITED when money arrives and debited when it
     * leaves, so its net debit is negative while it holds something: the
     * money available is the opposite of that net. A project whose pool
     * was never opened holds nothing.
     */
    private function poolBalance(string $projectId, string $currency): int
    {
        foreach ($this->trialBalance->forProject($projectId) as $balance) {
            if ($balance->kind !== SettlementAccount::PAYMENT_EXCEPTIONS
                || $balance->reference !== SettlementAccount::PROJECT_LEVEL
                || trim($balance->currency) !== strtoupper($currency)) {
                continue;
            }

            return -$balance->netDebitMinor();
        }

        return 0;
    }

    /**
     * ADR-0077 §4 — imputing an exception to a wallet: the money leaves
     * `payment_exceptions` and lands on the wallet of the party the
     * payment was for.
     *
     * Nothing moves while the resolution still waits for its second
     * approver (ADR-0081 S-15): the approval runs this same path, so the
     * money moves exactly once, in the same transaction as the state
     * change.
     */
    private function creditWalletFor(MoneyException $exception): void
    {
        if ($exception->status !== MoneyExceptionStatus::Resolved || $exception->bookedAmount <= 0) {
            return;
        }

        $externalRef = $exception->subjectRef;
        if ($externalRef === null || trim($externalRef) === '') {
            throw MoneyExceptionTransitionRefused::resolutionNotAllowed(
                $exception->id,
                MoneyExceptionResolution::AllocatedToWallet->value,
            );
        }

        $party = $this->partyFinder->findOrCreate(new FindOrCreatePartyCommand(
            $exception->projectId,
            $externalRef,
            'payment_recipient',
        ));

        $this->creditWallet->credit(new CreditWalletCommand(
            projectId: $exception->projectId,
            partyId: $party->id,
            amountMinor: $exception->bookedAmount,
            currency: $exception->currency,
            // The payment this money came from, when there is one — the
            // join key the reconciliation uses (ADR-0078 §3).
            externalRef: $exception->paymentId ?? $exception->id,
            // One allocation per exception, ever — the exception's own id
            // is naturally unique and doubles as the ledger-level
            // idempotency key (ADR-0033 §2).
            idempotencyKey: 'money_exception:'.$exception->id.':wallet',
            partyExternalRef: $externalRef,
            origin: new WalletCreditOrigin(WalletCreditSource::PaymentExceptions),
        ));
    }
}
