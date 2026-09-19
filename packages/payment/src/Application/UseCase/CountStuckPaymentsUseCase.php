<?php

declare(strict_types=1);

namespace Core\Payment\Application\UseCase;

use Core\Payment\Contracts\CountStuckPayments;
use Core\Payment\Domain\Port\PaymentRepository;
use Core\Support\Clock;

final readonly class CountStuckPaymentsUseCase implements CountStuckPayments
{
    public function __construct(
        private PaymentRepository $payments,
        private Clock $clock,
        private int $stuckAfterMinutes,
    ) {}

    public function count(): int
    {
        $threshold = $this->clock->now()->modify(sprintf('-%d minutes', $this->stuckAfterMinutes));

        return $this->payments->countStuckSince($threshold);
    }
}
