<?php

declare(strict_types=1);

namespace Core\Payment\Application\UseCase;

use Core\Payment\Contracts\ListStuckPayments;
use Core\Payment\Contracts\ListStuckPaymentsCommand;
use Core\Payment\Contracts\StuckPaymentView;
use Core\Payment\Domain\Model\Payment;
use Core\Payment\Domain\Port\PaymentRepository;
use Core\Support\Clock;

final readonly class ListStuckPaymentsUseCase implements ListStuckPayments
{
    public function __construct(
        private PaymentRepository $payments,
        private Clock $clock,
        private int $stuckAfterMinutes,
        private int $maxAgeHours = 24,
    ) {}

    public function list(ListStuckPaymentsCommand $command): array
    {
        $threshold = $this->clock->now()->modify(sprintf('-%d minutes', $this->stuckAfterMinutes));

        $stuck = $this->payments->findStuckSince($threshold, $command->limit, $this->clock->now()->modify(sprintf('-%d hours', $this->maxAgeHours)));

        return array_map(
            static fn (Payment $payment): StuckPaymentView => new StuckPaymentView(
                id: $payment->id,
                projectId: $payment->projectId,
                channel: $payment->channel,
                status: $payment->status->value,
                amount: $payment->amount,
                currency: $payment->currency,
                updatedAt: $payment->updatedAt,
            ),
            $stuck,
        );
    }
}
