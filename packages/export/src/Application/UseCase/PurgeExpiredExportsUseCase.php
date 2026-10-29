<?php

declare(strict_types=1);

namespace Core\Export\Application\UseCase;

use Core\Audit\Contracts\AuditEntry;
use Core\Audit\Contracts\AuditLogger;
use Core\Export\Contracts\PurgeExpiredExports;
use Core\Export\Domain\Port\ExportRepository;
use Core\Export\Domain\Port\ExportStore;
use Core\Support\Clock;

final readonly class PurgeExpiredExportsUseCase implements PurgeExpiredExports
{
    private const BATCH = 100;

    public function __construct(
        private ExportRepository $exports,
        private ExportStore $store,
        private AuditLogger $audit,
        private Clock $clock,
    ) {}

    public function purge(): int
    {
        $purged = 0;

        // Bounded batches: a row whose file is gone no longer matches, so
        // the loop ends when nothing is left to delete.
        do {
            $batch = $this->exports->findWithExpiredFile($this->clock->now(), self::BATCH);

            foreach ($batch as $export) {
                if ($export->filePath !== null) {
                    $this->store->delete($export->filePath);
                }

                $export->discardFile();
                $this->exports->save($export);
                $purged++;

                $this->audit->record(new AuditEntry(
                    actor: 'service:export-purge',
                    action: 'export.purged',
                    resource: 'export:'.$export->id,
                    before: null,
                    after: null,
                    context: [],
                    correlationId: null,
                    occurredAt: $this->clock->now(),
                    projectId: $export->projectId,
                ));
            }
        } while (count($batch) === self::BATCH);

        return $purged;
    }
}
