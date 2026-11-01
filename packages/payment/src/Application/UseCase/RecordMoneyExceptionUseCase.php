<?php

declare(strict_types=1);

namespace Core\Payment\Application\UseCase;

use Core\Payment\Contracts\RecordMoneyException;
use Core\Payment\Contracts\RecordMoneyExceptionCommand;
use Core\Payment\Domain\Model\MoneyException;
use Core\Payment\Domain\Model\MoneyExceptionKind;
use Core\Payment\Domain\Model\MoneyExceptionSeverity;
use Core\Payment\Domain\Port\MoneyExceptionRepository;
use Core\Support\Clock;
use Core\Support\Identifier;
use InvalidArgumentException;

final readonly class RecordMoneyExceptionUseCase implements RecordMoneyException
{
    public function __construct(
        private MoneyExceptionRepository $exceptions,
        private Identifier $identifier,
        private Clock $clock,
    ) {}

    public function record(RecordMoneyExceptionCommand $command): ?string
    {
        $kind = MoneyExceptionKind::tryFrom($command->kind)
            ?? throw new InvalidArgumentException(sprintf('Unknown money exception kind "%s".', $command->kind));
        $severity = MoneyExceptionSeverity::tryFrom($command->severity)
            ?? throw new InvalidArgumentException(sprintf('Unknown money exception severity "%s".', $command->severity));

        $exception = MoneyException::open(
            id: $this->identifier->generate(),
            projectId: $command->projectId,
            kind: $kind,
            severity: $severity,
            amount: $command->amountMinor,
            currency: $command->currency,
            bookedAmount: $command->bookedAmountMinor,
            paymentId: $command->paymentId,
            subjectRef: $command->subjectRef,
            summary: $command->summary,
            details: $command->details,
            now: $this->clock->now(),
        );

        return $this->exceptions->create($exception) ? $exception->id : null;
    }
}
