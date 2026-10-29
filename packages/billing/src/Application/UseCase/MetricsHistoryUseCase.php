<?php

declare(strict_types=1);

namespace Core\Billing\Application\UseCase;

use Core\Billing\Application\Port\MetricsSnapshotStore;
use Core\Billing\Contracts\MetricsHistory;
use Core\Support\Access\AccessScope;
use Core\Support\Clock;

final readonly class MetricsHistoryUseCase implements MetricsHistory
{
    public function __construct(
        private MetricsSnapshotStore $store,
        private Clock $clock,
    ) {}

    public function forProject(string $projectId, AccessScope $scope, int $days): array
    {
        if (! $scope->allowsProject($projectId)) {
            return [];
        }

        $days = max(1, min(366, $days));

        return $this->store->since($projectId, $this->clock->now()->modify('-'.($days - 1).' days')->format('Y-m-d'));
    }
}
