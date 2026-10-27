<?php

declare(strict_types=1);

namespace Core\Payment\Domain\Port;

use Core\Payment\Domain\Model\MoneyException;
use Core\Payment\Domain\Model\MoneyExceptionStatus;

interface MoneyExceptionRepository
{
    /**
     * Stores a new exception. Returns `false`, without writing, when an
     * OPEN exception already exists for the same payment and kind (a
     * replay, or a control that has already reported the violation).
     *
     * A RESOLVED exception never blocks: the invariant it carried may
     * break again, and the next pass of the control must be able to
     * open a new one (ADR-0078 §2).
     */
    public function create(MoneyException $exception): bool;

    public function save(MoneyException $exception): void;

    public function find(string $id): ?MoneyException;

    /** Locked read, for a resolution racing another one on the same row. */
    public function findForUpdate(string $id): ?MoneyException;

    /**
     * @param list<string>|null $projectIds `null` = every project
     * @return array{items: list<MoneyException>, total: int}
     */
    public function list(?array $projectIds, ?MoneyExceptionStatus $status, int $page, int $perPage): array;

    /**
     * @param list<string>|null $projectIds
     */
    public function countUnresolved(?array $projectIds): int;

    /**
     * Whether an unresolved control failure already exists for this check
     * and key — the hourly invariant check must not open the same exception
     * every hour (ADR-0078 §2).
     */
    public function hasUnresolvedControlFailure(string $projectId, string $check, string $key): bool;
}
