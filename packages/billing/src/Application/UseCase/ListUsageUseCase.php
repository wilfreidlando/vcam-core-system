<?php

declare(strict_types=1);

namespace Core\Billing\Application\UseCase;

use Core\Billing\Application\Port\UsageReader;
use Core\Billing\Contracts\ListUsage;
use Core\Billing\Contracts\UsagePage;
use Core\Support\Access\AccessScope;

final readonly class ListUsageUseCase implements ListUsage
{
    private const MAX = 100;

    public function __construct(private UsageReader $reader) {}

    public function counters(string $projectId, ?string $filter, ?string $customerRef, ?string $cursor, int $limit, AccessScope $scope): UsagePage
    {
        if (! $scope->allowsProject($projectId)) {
            return new UsagePage([]);
        }

        return $this->reader->counters($projectId, in_array($filter, ['over80', 'exceeded'], true) ? $filter : null, $customerRef, $cursor, $this->bounded($limit));
    }

    public function records(string $projectId, ?string $meter, ?string $customerRef, ?string $cursor, int $limit, AccessScope $scope): UsagePage
    {
        if (! $scope->allowsProject($projectId)) {
            return new UsagePage([]);
        }

        return $this->reader->records($projectId, $meter, $customerRef, $cursor, $this->bounded($limit));
    }

    private function bounded(int $limit): int
    {
        return min(self::MAX, max(1, $limit));
    }
}
