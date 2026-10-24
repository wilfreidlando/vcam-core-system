<?php

declare(strict_types=1);

namespace Core\Payment\Application\UseCase;

use Core\Payment\Application\Support\MoneyExceptionViews;
use Core\Payment\Contracts\MoneyExceptionView;
use Core\Payment\Contracts\ResolveMoneyException;
use Core\Payment\Contracts\ResolveMoneyExceptionCommand;
use Core\Payment\Domain\Exception\MoneyExceptionNotFound;
use Core\Payment\Domain\Exception\MoneyExceptionTransitionRefused;
use Core\Payment\Domain\Model\MoneyException;
use Core\Payment\Domain\Model\MoneyExceptionResolution;
use Core\Payment\Domain\Port\MoneyExceptionRepository;
use Core\Payment\Domain\Port\SettlementLedger;
use Core\Support\Clock;
use Core\Support\UnitOfWork;

/**
 * ADR-0077 §4, ADR-0081 S-15 — the state change and its ledger movement
 * share one transaction, under a row lock: two people resolving the same
 * exception at once cannot both book it.
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
    ) {}

    public function resolve(ResolveMoneyExceptionCommand $command): MoneyExceptionView
    {
        $resolution = MoneyExceptionResolution::tryFrom($command->resolution)
            ?? throw MoneyExceptionTransitionRefused::resolutionNotAllowed($command->exceptionId, $command->resolution);

        return $this->unitOfWork->transactional(function () use ($command, $resolution): MoneyExceptionView {
            $exception = $this->locked($command->exceptionId);

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

    public function approve(string $exceptionId, string $approverOperatorId): MoneyExceptionView
    {
        return $this->unitOfWork->transactional(function () use ($exceptionId, $approverOperatorId): MoneyExceptionView {
            return $this->persist($this->locked($exceptionId)->approve($approverOperatorId, $this->clock->now()));
        });
    }

    public function reject(string $exceptionId, string $approverOperatorId): MoneyExceptionView
    {
        return $this->unitOfWork->transactional(function () use ($exceptionId, $approverOperatorId): MoneyExceptionView {
            return $this->persist($this->locked($exceptionId)->reject($approverOperatorId));
        });
    }

    private function locked(string $exceptionId): MoneyException
    {
        return $this->exceptions->findForUpdate($exceptionId) ?? throw MoneyExceptionNotFound::withId($exceptionId);
    }

    private function persist(MoneyException $exception): MoneyExceptionView
    {
        $this->exceptions->save($exception);
        $this->ledger->recordExceptionResolution($exception);

        return MoneyExceptionViews::from($exception);
    }
}
