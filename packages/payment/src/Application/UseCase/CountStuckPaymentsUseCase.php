<?php

declare(strict_types=1);

namespace Core\Payment\Application\UseCase;

use Core\Payment\Contracts\CountStuckPayments;
use Core\Payment\Domain\Port\PaymentRepository;
use Core\Support\Access\AccessScope;
use Core\Support\Clock;

final readonly class CountStuckPaymentsUseCase implements CountStuckPayments
{
    public function __construct(
        private PaymentRepository $payments,
        private Clock $clock,
        private int $stuckAfterMinutes,
        private int $maxAgeHours = 24,
    ) {}

    public function count(AccessScope $scope): int
    {
        $threshold = $this->clock->now()->modify(sprintf('-%d minutes', $this->stuckAfterMinutes));

        // ADR-0069 §2 — the scope filters the count in the query: the
        // stuck payments of another tenant are never counted.
        return $this->payments->countStuckSince(
            $threshold,
            $this->clock->now()->modify(sprintf('-%d hours', $this->maxAgeHours)),
            $scope->projectFilter(),
        );
    }
}
