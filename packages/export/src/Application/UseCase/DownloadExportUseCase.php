<?php

declare(strict_types=1);

namespace Core\Export\Application\UseCase;

use Core\Audit\Contracts\AuditEntry;
use Core\Audit\Contracts\AuditLogger;
use Core\Export\Contracts\DownloadExport;
use Core\Export\Contracts\ExportDownloadView;
use Core\Export\Contracts\ExportNotAvailable;
use Core\Export\Contracts\ExportNotFound;
use Core\Export\Domain\Model\ProjectExport;
use Core\Export\Domain\Port\ExportRepository;
use Core\Export\Domain\Port\ExportStore;
use Core\Support\Clock;

final readonly class DownloadExportUseCase implements DownloadExport
{
    public function __construct(
        private ExportRepository $exports,
        private ExportStore $store,
        private AuditLogger $audit,
        private Clock $clock,
    ) {}

    public function prepare(string $exportId): ExportDownloadView
    {
        $export = $this->available($exportId);

        return new ExportDownloadView(
            $export->id,
            $export->projectId,
            sprintf('export-%s-%s.zip', substr($export->projectId, 0, 8), $export->createdAt->format('Ymd-His')),
        );
    }

    public function stream(string $exportId, mixed $output): void
    {
        $export = $this->available($exportId);

        // The signed link is the only authentication of this route: every
        // download leaves a trace.
        $this->audit->record(new AuditEntry(
            actor: 'signed-link',
            action: 'export.downloaded',
            resource: 'export:'.$export->id,
            before: null,
            after: null,
            context: [],
            correlationId: null,
            occurredAt: $this->clock->now(),
            projectId: $export->projectId,
        ));

        $this->store->stream((string) $export->filePath, $output);
    }

    private function available(string $exportId): ProjectExport
    {
        $export = $this->exports->find($exportId) ?? throw ExportNotFound::withId($exportId);

        if (! $export->isAvailable($this->clock->now())) {
            throw ExportNotAvailable::withId($exportId);
        }

        return $export;
    }
}
