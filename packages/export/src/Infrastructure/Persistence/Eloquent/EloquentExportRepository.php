<?php

declare(strict_types=1);

namespace Core\Export\Infrastructure\Persistence\Eloquent;

use Core\Export\Domain\Model\ExportStatus;
use Core\Export\Domain\Model\ProjectExport;
use Core\Export\Domain\Port\ExportRepository;
use DateTimeImmutable;

final class EloquentExportRepository implements ExportRepository
{
    public function create(ProjectExport $export): void
    {
        ExportRecord::query()->create($this->attributes($export));
    }

    public function save(ProjectExport $export): void
    {
        ExportRecord::query()->whereKey($export->id)->update($this->attributes($export));
    }

    public function find(string $id): ?ProjectExport
    {
        $record = ExportRecord::query()->find($id);

        return $record === null ? null : $this->toDomain($record);
    }

    public function findByProject(string $projectId, int $limit, ?array $projectIds = null): array
    {
        // `toBase()` + `hydrate()`: see EloquentPaymentRepository::findStuckSince().
        $query = ExportRecord::query()->toBase()->where('project_id', $projectId);

        if ($projectIds !== null) {
            // ADR-0069 §2 — the scope filters at the query level.
            $query->whereIn('project_id', $projectIds);
        }

        return $this->hydrate($query->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get()->all());
    }

    public function findWithExpiredFile(DateTimeImmutable $now, int $limit): array
    {
        $query = ExportRecord::query()->toBase()
            ->whereNotNull('file_path')
            ->where('expires_at', '<=', $now->format('Y-m-d H:i:s.uP'))
            ->orderBy('expires_at')
            ->limit($limit);

        return $this->hydrate($query->get()->all());
    }

    /**
     * @param array<array-key, mixed> $rows
     * @return list<ProjectExport>
     */
    private function hydrate(array $rows): array
    {
        $records = ExportRecord::hydrate(array_map(static fn (mixed $row): array => (array) $row, array_values($rows)));
        $exports = [];

        foreach ($records as $record) {
            $exports[] = $this->toDomain($record);
        }

        return $exports;
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(ProjectExport $export): array
    {
        return [
            'id' => $export->id,
            'project_id' => $export->projectId,
            'status' => $export->status->value,
            'requested_by' => $export->requestedBy,
            'created_at' => $export->createdAt->format('Y-m-d H:i:s.uP'),
            'finished_at' => $export->finishedAt?->format('Y-m-d H:i:s.uP'),
            'expires_at' => $export->expiresAt->format('Y-m-d H:i:s.uP'),
            'file_path' => $export->filePath,
            'error' => $export->error,
        ];
    }

    private function toDomain(ExportRecord $record): ProjectExport
    {
        return new ProjectExport(
            id: $record->id,
            projectId: $record->project_id,
            status: ExportStatus::from($record->status),
            requestedBy: $record->requested_by,
            createdAt: DateTimeImmutable::createFromInterface($record->created_at),
            finishedAt: $record->finished_at === null ? null : DateTimeImmutable::createFromInterface($record->finished_at),
            expiresAt: DateTimeImmutable::createFromInterface($record->expires_at),
            filePath: $record->file_path,
            error: $record->error,
        );
    }
}
