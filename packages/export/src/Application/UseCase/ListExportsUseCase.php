<?php

declare(strict_types=1);

namespace Core\Export\Application\UseCase;

use Core\Export\Contracts\ListExports;
use Core\Export\Domain\Model\ProjectExport;
use Core\Export\Domain\Port\ExportRepository;
use Core\Support\Access\AccessScope;
use Core\Support\Clock;

final readonly class ListExportsUseCase implements ListExports
{
    public function __construct(
        private ExportRepository $exports,
        private Clock $clock,
    ) {}

    public function list(string $projectId, int $limit, AccessScope $scope): array
    {
        if (! $scope->allowsProject($projectId)) {
            return [];
        }

        $now = $this->clock->now();

        return array_map(
            static fn (ProjectExport $export) => ExportViews::from($export, $now),
            $this->exports->findByProject($projectId, max(1, min(100, $limit)), $scope->projectFilter()),
        );
    }
}
