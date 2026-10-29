<?php

declare(strict_types=1);

namespace Core\Export\Application\UseCase;

use Core\Export\Contracts\ExportNotFound;
use Core\Export\Contracts\ExportView;
use Core\Export\Contracts\GetExport;
use Core\Export\Domain\Port\ExportRepository;
use Core\Support\Access\AccessScope;
use Core\Support\Clock;

final readonly class GetExportUseCase implements GetExport
{
    public function __construct(
        private ExportRepository $exports,
        private Clock $clock,
    ) {}

    public function get(string $exportId, AccessScope $scope): ExportView
    {
        $export = $this->exports->find($exportId);

        // ADR-0069 §2 — another project's export is « not found ».
        if ($export === null || ! $scope->allowsProject($export->projectId)) {
            throw ExportNotFound::withId($exportId);
        }

        return ExportViews::from($export, $this->clock->now());
    }
}
