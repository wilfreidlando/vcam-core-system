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

    /** @var array<string, DateTimeImmutable> `reconciled_at` per payment id (ADR-0067) */
    public array $reconciledAt = [];

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

    /**
     * No real locking to model in-memory (single-threaded test doubles) —
     * concurrency itself is proven separately at the Eloquent/PostgreSQL
     * level, not through this fake.
     */
    public function findByProviderTransactionRefForUpdate(string $providerCode, string $providerTransactionRef): ?Payment
    {
        return $this->findByProviderTransactionRef($providerCode, $providerTransactionRef);
    }

    public function count(): int
    {
        return count($this->payments);
    }

    /**
     * The Domain `Payment` carries no creation timestamp (unlike the
     * Eloquent record), so this fake ignores `$createdAfter` and treats
     * every payment as last changed before `$threshold`. It does honour
     * `reconciled_at` (ADR-0067): a payment checked at or after
     * `$threshold` is not a candidate, and never-checked payments come
     * first (insertion order), then the least recently checked.
     */
    public function findStuckSince(DateTimeImmutable $threshold, int $limit, DateTimeImmutable $createdAfter): array
    {
        return array_slice($this->stuckCandidates($threshold), 0, $limit);
    }

    public function markReconciliationChecked(string $paymentId, DateTimeImmutable $checkedAt): void
    {
        $this->reconciledAt[$paymentId] = $checkedAt;
    }

    public function findByProject(?string $projectId, ?PaymentStatus $status, int $page, int $perPage): array
    {
        $matching = array_values(array_filter(
            $this->payments,
            static fn (Payment $payment): bool => ($projectId === null || $payment->projectId === $projectId)
                && ($status === null || $payment->status === $status),
        ));

        $total = count($matching);
        $offset = ($page - 1) * $perPage;

        return ['items' => array_slice($matching, $offset, $perPage), 'total' => $total];
    }

    /**
     * Same candidate set as findStuckSince().
     */
    public function countStuckSince(DateTimeImmutable $threshold, DateTimeImmutable $createdAfter): int
    {
        return count($this->stuckCandidates($threshold));
    }

    /**
     * Same reasoning: ignores `$since`, counts every payment held.
     */
    public function countRecentByStatus(DateTimeImmutable $since): array
    {
        $total = count($this->payments);
        $succeeded = count(array_filter(
            $this->payments,
            static fn (Payment $payment): bool => $payment->status === PaymentStatus::Succeeded,
        ));

        return ['total' => $total, 'succeeded' => $succeeded];
    }

    /** @return list<Payment> */
    private function stuckCandidates(DateTimeImmutable $threshold): array
    {
        $stuck = array_values(array_filter(
            $this->payments,
            fn (Payment $payment): bool => in_array($payment->status, [PaymentStatus::Pending, PaymentStatus::Processing], true)
                && (! isset($this->reconciledAt[$payment->id]) || $this->reconciledAt[$payment->id] < $threshold),
        ));
        $ordre = array_flip(array_keys($this->payments));
        usort($stuck, fn (Payment $a, Payment $b): int => [$this->reconciledAt[$a->id] ?? null, $ordre[$a->id]] <=> [$this->reconciledAt[$b->id] ?? null, $ordre[$b->id]]);

        return $stuck;
    }
}
