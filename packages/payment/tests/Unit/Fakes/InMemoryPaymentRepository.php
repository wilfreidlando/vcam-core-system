<?php

declare(strict_types=1);

namespace Core\Payment\Tests\Unit\Fakes;

use Core\Payment\Domain\Exception\PaymentAlreadyExists;
use Core\Payment\Domain\Model\Payment;
use Core\Payment\Domain\Port\PaymentRepository;

final class InMemoryPaymentRepository implements PaymentRepository
{
    /**
     * When set, the next create() throws PaymentAlreadyExists instead of
     * persisting, and this winning row becomes visible immediately
     * afterwards — models a concurrent idempotency-key race.
     */
    public ?Payment $concurrentWinner = null;

    /** @var array<string, Payment> */
    private array $payments = [];

    public function create(Payment $payment): void
    {
        if ($this->concurrentWinner !== null) {
            $winner = $this->concurrentWinner;
            $this->concurrentWinner = null;
            $this->payments[$winner->id] = $winner;

            throw new PaymentAlreadyExists;
        }

        $this->payments[$payment->id] = $payment;
    }

    public function save(Payment $payment): void
    {
        $this->payments[$payment->id] = $payment;
    }

    public function find(string $id): ?Payment
    {
        return $this->payments[$id] ?? null;
    }

    public function findByIdempotencyKey(string $projectId, string $idempotencyKey): ?Payment
    {
        foreach ($this->payments as $payment) {
            if ($payment->projectId === $projectId && $payment->idempotencyKey === $idempotencyKey) {
                return $payment;
            }
        }

        return null;
    }

    public function findByProviderTransactionRef(string $providerCode, string $providerTransactionRef): ?Payment
    {
        foreach ($this->payments as $payment) {
            if ($payment->providerCode === $providerCode && $payment->providerTransactionRef === $providerTransactionRef) {
                return $payment;
            }
        }

        return null;
    }

    public function count(): int
    {
        return count($this->payments);
    }
}
