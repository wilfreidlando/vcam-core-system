<?php

declare(strict_types=1);

namespace Core\Payment\Application\UseCase;

use Core\Payment\Application\Port\SandboxPaymentSimulator;
use Core\Payment\Contracts\Events\PaymentStatusChanged;
use Core\Payment\Contracts\PaymentStatusChangeHandler;
use Core\Payment\Contracts\PaymentView;
use Core\Payment\Contracts\SimulateSandboxPayment;
use Core\Payment\Contracts\SimulateSandboxPaymentCommand;
use Core\Payment\Domain\Exception\PaymentNotFound;
use Core\Payment\Domain\Exception\PaymentNotSimulable;
use Core\Payment\Domain\Port\PaymentRepository;
use Core\Payment\PaymentStatus;
use Core\Support\Clock;
use DateTimeZone;
use InvalidArgumentException;

final readonly class SimulateSandboxPaymentUseCase implements SimulateSandboxPayment
{
    private const ALLOWED = ['succeeded', 'failed', 'expired', 'canceled'];

    public function __construct(
        private PaymentRepository $payments,
        private PaymentStatusChangeHandler $statusChanges,
        private Clock $clock,
    ) {}

    public function simulate(SimulateSandboxPaymentCommand $command): PaymentView
    {
        if (! in_array($command->status, self::ALLOWED, true)) {
            throw new InvalidArgumentException('status must be one of: '.implode(', ', self::ALLOWED).'.');
        }

        $payment = match (true) {
            $command->paymentId !== null => $this->payments->find($command->paymentId),
            $command->transactionRef !== null => $this->payments->findByProviderTransactionRef(SandboxPaymentSimulator::PROVIDER_CODE, $command->transactionRef),
            default => null,
        };

        $reference = $command->paymentId ?? $command->transactionRef ?? '';
        if ($payment === null || ($command->projectId !== null && $payment->projectId !== $command->projectId)) {
            throw PaymentNotFound::withId($reference);
        }

        if (! $payment->isSandbox() || $payment->providerCode !== SandboxPaymentSimulator::PROVIDER_CODE) {
            throw PaymentNotSimulable::notSandbox($payment->id);
        }

        if ($payment->status->isTerminal()) {
            throw PaymentNotSimulable::alreadyFinal($payment->id, $payment->status->value);
        }

        $this->statusChanges->handle(new PaymentStatusChanged(
            providerCode: SandboxPaymentSimulator::PROVIDER_CODE,
            transactionRef: $payment->providerTransactionRef,
            appTransactionRef: $payment->idempotencyKey,
            status: PaymentStatus::from($command->status),
            reason: $command->reason ?? ($command->status === 'succeeded' ? null : 'Simulated in sandbox'),
            occurredAt: $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
        ), 'sandbox_simulation');

        $updated = $this->payments->find($payment->id) ?? $payment;

        return new PaymentView(
            id: $updated->id,
            projectId: $updated->projectId,
            channel: $updated->channel,
            status: $updated->status->value,
            amount: $updated->amount,
            currency: $updated->currency,
            requiresAction: $updated->requiresAction,
            actionHint: $updated->actionHint,
            paymentUrl: $updated->paymentUrl,
            errorMessage: $updated->errorMessage,
            idempotencyKey: $updated->idempotencyKey,
            callbackUrl: $updated->callbackUrl,
        );
    }
}
