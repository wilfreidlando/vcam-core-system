<?php

declare(strict_types=1);

namespace Core\Export\Application\UseCase;

use Core\Audit\Contracts\AuditEntry;
use Core\Audit\Contracts\AuditLogger;
use Core\Export\Contracts\ExportView;
use Core\Export\Contracts\RequestExport;
use Core\Export\Contracts\RequestExportCommand;
use Core\Export\Domain\Model\ProjectExport;
use Core\Export\Domain\Port\ExportQueue;
use Core\Export\Domain\Port\ExportRepository;
use Core\Support\Clock;
use Core\Support\Identifier;

final readonly class RequestExportUseCase implements RequestExport
{
    public function __construct(
        private ExportRepository $exports,
        private ExportQueue $queue,
        private AuditLogger $audit,
        private Clock $clock,
        private Identifier $identifier,
    ) {}

    public function request(RequestExportCommand $command): ExportView
    {
        $now = $this->clock->now();
        $export = ProjectExport::queue($this->identifier->generate(), $command->projectId, $command->requestedBy, $now);
        $this->exports->create($export);

        // ADR-0088 §3 — who, when, what.
        $this->audit->record(new AuditEntry(
            actor: $command->requestedBy,
            action: 'export.requested',
            resource: 'export:'.$export->id,
            before: null,
            after: ['status' => $export->status->value, 'expires_at' => $export->expiresAt->format(DATE_ATOM)],
            context: [],
            correlationId: $command->correlationId,
            occurredAt: $now,
            projectId: $command->projectId,
            organizationId: $command->organizationId,
        ));

        $this->queue->push($export->id);

        return ExportViews::from($export, $now);
    }
}
