<?php

declare(strict_types=1);

namespace Core\Export\Application\UseCase;

use Core\Audit\Contracts\AuditEntry;
use Core\Audit\Contracts\AuditLogger;
use Core\Export\Contracts\RunExport;
use Core\Export\Domain\Model\ExportStatus;
use Core\Export\Domain\Port\ExportRepository;
use Core\Export\Domain\Port\ExportStore;
use Core\Support\Clock;
use Throwable;

final readonly class RunExportUseCase implements RunExport
{
    public function __construct(
        private ExportRepository $exports,
        private ExportStore $store,
        private AuditLogger $audit,
        private Clock $clock,
    ) {}

    public function run(string $exportId): void
    {
        $export = $this->exports->find($exportId);

        // Idempotent: only a queued export is built, a replayed job does nothing.
        if ($export === null || $export->status !== ExportStatus::Queued) {
            return;
        }

        $export->markRunning();
        $this->exports->save($export);

        // The moment the data is read « as of »: the trial balance and the
        // ledger transactions are exact at this instant. The ledger reads
        // « strictly before » at the second, so the instant is the start of
        // the next whole second: nothing written up to now is left out.
        $now = $this->clock->now();
        $asOf = $now->setTime((int) $now->format('G'), (int) $now->format('i'), (int) $now->format('s'))->modify('+1 second');

        try {
            $path = $this->store->build($export, $asOf);
        } catch (Throwable $e) {
            // The cause is reported (log, monitoring); the record keeps the
            // name of the failure, never a message that could carry data.
            report($e);
            $export->markFailed('Export failed ('.$e::class.').', $this->clock->now());
            $this->exports->save($export);
            $this->record($export->projectId, $export->id, 'export.failed', $export->status->value);

            return;
        }

        $export->markDone($path, $this->clock->now());
        $this->exports->save($export);
        $this->record($export->projectId, $export->id, 'export.completed', $export->status->value);
    }

    private function record(string $projectId, string $exportId, string $action, string $status): void
    {
        $this->audit->record(new AuditEntry(
            actor: 'service:export',
            action: $action,
            resource: 'export:'.$exportId,
            before: null,
            after: ['status' => $status],
            context: [],
            correlationId: null,
            occurredAt: $this->clock->now(),
            projectId: $projectId,
        ));
    }
}
