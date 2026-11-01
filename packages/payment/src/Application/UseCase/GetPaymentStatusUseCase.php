<?php

declare(strict_types=1);

namespace Core\Payment\Application\UseCase;

use Core\Payment\Application\Support\PaymentViews;
use Core\Payment\Contracts\GetPaymentStatus;
use Core\Payment\Contracts\GetPaymentStatusCommand;
use Core\Payment\Contracts\PaymentView;
use Core\Payment\Domain\Exception\PaymentNotFound;
use Core\Payment\Domain\Port\PaymentRepository;
use Core\Support\Access\AccessScope;

final readonly class GetPaymentStatusUseCase implements GetPaymentStatus
{
    public function __construct(
        private PaymentRepository $payments,
    ) {}

    public function get(GetPaymentStatusCommand $command, AccessScope $scope): PaymentView
    {
        $payment = $this->payments->find($command->paymentId);

        if ($payment === null
            || $payment->projectId !== $command->requestingProjectId) {
            // Same exception for "does not exist" and "belongs to another
            // project": distinguishing them would let a caller enumerate
            // payment ids that exist outside its own project.
            throw PaymentNotFound::withId($command->paymentId);
        }

        // ADR-0069 §2 — the console may only read a payment of a project it
        // may see; the public API's scope is its own project, already
        // checked above.
        if (! $scope->allowsProject($payment->projectId)) {
            throw PaymentNotFound::withId($command->paymentId);
        }

        return PaymentViews::from($payment);
    }
}
