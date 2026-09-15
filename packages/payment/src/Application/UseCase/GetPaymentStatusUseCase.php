<?php

declare(strict_types=1);

namespace Core\Payment\Application\UseCase;

use Core\Payment\Contracts\GetPaymentStatus;
use Core\Payment\Contracts\GetPaymentStatusCommand;
use Core\Payment\Contracts\PaymentView;
use Core\Payment\Domain\Exception\PaymentNotFound;
use Core\Payment\Domain\Port\PaymentRepository;

final readonly class GetPaymentStatusUseCase implements GetPaymentStatus
{
    public function __construct(
        private PaymentRepository $payments,
    ) {}

    public function get(GetPaymentStatusCommand $command): PaymentView
    {
        $payment = $this->payments->find($command->paymentId);

        if ($payment === null || $payment->projectId !== $command->requestingProjectId) {
            // Same exception for "does not exist" and "belongs to another
            // project": distinguishing them would let a caller enumerate
            // payment ids that exist outside its own project.
            throw PaymentNotFound::withId($command->paymentId);
        }

        return new PaymentView(
            id: $payment->id,
            channel: $payment->channel,
            status: $payment->status,
            amountMinorUnits: $payment->amountMinorUnits,
            currency: $payment->currency,
            requiresAction: $payment->requiresAction,
            actionHint: $payment->actionHint,
            paymentUrl: $payment->paymentUrl,
            errorMessage: $payment->errorMessage,
        );
    }
}
