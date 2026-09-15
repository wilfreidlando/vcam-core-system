<?php

declare(strict_types=1);

namespace Core\Payment\Application\UseCase;

use Core\Payment\Contracts\ListPayments;
use Core\Payment\Contracts\ListPaymentsCommand;
use Core\Payment\Contracts\PaymentPage;
use Core\Payment\Contracts\PaymentView;
use Core\Payment\Domain\Model\Payment;
use Core\Payment\Domain\Port\PaymentRepository;
use Core\Payment\PaymentStatus;

final readonly class ListPaymentsUseCase implements ListPayments
{
    public function __construct(
        private PaymentRepository $payments,
    ) {}

    public function list(ListPaymentsCommand $command): PaymentPage
    {
        $status = $command->status !== null ? PaymentStatus::tryFrom($command->status) : null;

        $result = $this->payments->findByProject($command->projectId, $status, $command->page, $command->perPage);

        $items = array_map(
            static fn (Payment $payment): PaymentView => new PaymentView(
                id: $payment->id,
                channel: $payment->channel,
                status: $payment->status,
                amount: $payment->amount,
                currency: $payment->currency,
                requiresAction: $payment->requiresAction,
                actionHint: $payment->actionHint,
                paymentUrl: $payment->paymentUrl,
                errorMessage: $payment->errorMessage,
            ),
            $result['items'],
        );

        return new PaymentPage($items, $result['total'], $command->page, $command->perPage);
    }
}
