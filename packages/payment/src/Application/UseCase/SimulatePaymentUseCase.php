<?php

declare(strict_types=1);

namespace Core\Payment\Application\UseCase;

use Core\Payment\Application\Port\PaymentSimulator;
use Core\Payment\Application\Support\PaymentViews;
use Core\Payment\Contracts\Events\PaymentStatusChanged;
use Core\Payment\Contracts\PaymentStatusChangeHandler;
use Core\Payment\Contracts\PaymentView;
use Core\Payment\Contracts\SimulatePayment;
use Core\Payment\Contracts\SimulatePaymentCommand;
use Core\Payment\Domain\Exception\PaymentNotFound;
use Core\Payment\Domain\Exception\PaymentNotSimulable;
use Core\Payment\Domain\Port\PaymentRepository;
use Core\Payment\PaymentStatus;
use Core\Support\Clock;
use DateTimeZone;
use InvalidArgumentException;

final readonly class SimulatePaymentUseCase implements SimulatePayment
{
    private const ALLOWED = ['succeeded', 'failed', 'expired', 'canceled'];

    public function __construct(
        private PaymentRepository $payments,
        private PaymentStatusChangeHandler $statusChanges,
        private PaymentSimulator $simulator,
        private Clock $clock,
    ) {}

    public function simulate(SimulatePaymentCommand $command): PaymentView
    {
        if (! $this->simulator->isEnabled()) {
            throw PaymentNotSimulable::simulatorDisabled();
        }

        if (! in_array($command->status, self::ALLOWED, true)) {
            throw new InvalidArgumentException('status must be one of: '.implode(', ', self::ALLOWED).'.');
        }

        $payment = match (true) {
            $command->paymentId !== null => $this->payments->find($command->paymentId),
            $command->transactionRef !== null => $this->payments->findByProviderTransactionRef(PaymentSimulator::PROVIDER_CODE, $command->transactionRef),
            default => null,
        };

        $reference = $command->paymentId ?? $command->transactionRef ?? '';
        if ($payment === null || ($command->projectId !== null && $payment->projectId !== $command->projectId)) {
            throw PaymentNotFound::withId($reference);
        }

        if (! $payment->isSimulated()) {
            throw PaymentNotSimulable::notSimulated($payment->id);
        }

        if ($payment->status->isTerminal()) {
            throw PaymentNotSimulable::alreadyFinal($payment->id, $payment->status->value);
        }

        $this->statusChanges->handle(new PaymentStatusChanged(
            providerCode: PaymentSimulator::PROVIDER_CODE,
            transactionRef: $payment->providerTransactionRef,
            appTransactionRef: $payment->idempotencyKey,
            status: PaymentStatus::from($command->status),
            reason: $command->reason ?? ($command->status === 'succeeded' ? null : 'Simulated'),
            occurredAt: $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
        ), 'simulation');

        $updated = $this->payments->find($payment->id) ?? $payment;

        return PaymentViews::from($updated);
    }
}
