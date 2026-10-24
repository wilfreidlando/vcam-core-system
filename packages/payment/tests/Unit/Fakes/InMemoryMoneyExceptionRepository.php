<?php

declare(strict_types=1);

namespace Core\Payment\Tests\Unit\Fakes;

use Core\Payment\Domain\Model\MoneyException;
use Core\Payment\Domain\Model\MoneyExceptionStatus;
use Core\Payment\Domain\Port\MoneyExceptionRepository;

final class InMemoryMoneyExceptionRepository implements MoneyExceptionRepository
{
    /** @var array<string, MoneyException> */
    public array $exceptions = [];

    public function create(MoneyException $exception): bool
    {
        foreach ($this->exceptions as $existing) {
            if ($exception->paymentId !== null && $existing->paymentId === $exception->paymentId && $existing->kind === $exception->kind) {
                return false;
            }
        }

        $this->exceptions[$exception->id] = $exception;

        return true;
    }

    public function save(MoneyException $exception): void
    {
        $this->exceptions[$exception->id] = $exception;
    }

    public function find(string $id): ?MoneyException
    {
        return $this->exceptions[$id] ?? null;
    }

    public function findForUpdate(string $id): ?MoneyException
    {
        return $this->find($id);
    }

    public function list(?array $projectIds, ?MoneyExceptionStatus $status, int $page, int $perPage): array
    {
        $items = array_values(array_filter(
            $this->exceptions,
            static fn (MoneyException $e): bool => ($projectIds === null || in_array($e->projectId, $projectIds, true))
                && ($status === null || $e->status === $status),
        ));

        return ['items' => array_slice($items, ($page - 1) * $perPage, $perPage), 'total' => count($items)];
    }

    public function countUnresolved(?array $projectIds): int
    {
        return count(array_filter(
            $this->exceptions,
            static fn (MoneyException $e): bool => $e->status !== MoneyExceptionStatus::Resolved
                && ($projectIds === null || in_array($e->projectId, $projectIds, true)),
        ));
    }

    public function hasUnresolvedControlFailure(string $projectId, string $check, string $key): bool
    {
        foreach ($this->exceptions as $e) {
            if ($e->projectId === $projectId
                && $e->kind === \Core\Payment\Domain\Model\MoneyExceptionKind::InvariantViolation
                && $e->status !== MoneyExceptionStatus::Resolved
                && ($e->details['check'] ?? null) === $check
                && ($e->details['key'] ?? null) === $key) {
                return true;
            }
        }

        return false;
    }

    /** @return list<MoneyException> */
    public function all(): array
    {
        return array_values($this->exceptions);
    }
}
