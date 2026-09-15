<?php

declare(strict_types=1);

namespace Core\Payment\Tests\Unit\Fakes;

use Core\Payment\Domain\Exception\PaymentAlreadyExists;
use Core\Payment\Domain\Model\Payment;
use Core\Payment\Domain\Port\PaymentRepository;
use Core\Payment\PaymentStatus;
use DateTimeImmutable;

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

    /**
     * The Domain `Payment` carries no timestamp (unlike the Eloquent
     * record), so this fake ignores `$threshold` and simply returns every
     * non-terminal payment in insertion order, up to `$limit` — enough for
     * `ReconcileStuckPaymentsUseCase`'s unit tests, which control "stuck"
     * entirely via which payments they add.
     */
    public function findStuckSince(DateTimeImmutable $threshold, int $limit): array
    {
        $stuck = array_values(array_filter(
            $this->payments,
            static fn (Payment $payment): bool => in_array($payment->status, [PaymentStatus::Pending, PaymentStatus::Processing], true),
        ));

        return array_slice($stuck, 0, $limit);
    }

    public function findByProject(string $projectId, ?PaymentStatus $status, int $page, int $perPage): array
    {
        $matching = array_values(array_filter(
            $this->payments,
            static fn (Payment $payment): bool => $payment->projectId === $projectId
                && ($status === null || $payment->status === $status),
        ));

        $total = count($matching);
        $offset = ($page - 1) * $perPage;

        return ['items' => array_slice($matching, $offset, $perPage), 'total' => $total];
    }
}
