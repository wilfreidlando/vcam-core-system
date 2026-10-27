<?php

declare(strict_types=1);

namespace Core\Payment\Infrastructure\Persistence\Eloquent;

use Core\Payment\Domain\Model\ReconciliationRun;
use Core\Payment\Domain\Model\ReconciliationRunLevel;
use Core\Payment\Domain\Model\ReconciliationRunStatus;
use Core\Payment\Domain\Port\ReconciliationRunRepository;
use DateTimeImmutable;

final class EloquentReconciliationRunRepository implements ReconciliationRunRepository
{
    public function record(ReconciliationRun $run): void
    {
        ReconciliationRunRecord::query()->create([
            'id' => $run->id,
            'project_id' => $run->projectId,
            'provider_code' => $run->providerCode,
            'level' => $run->level->value,
            'window_start' => $run->windowStart,
            'window_end' => $run->windowEnd,
            'status' => $run->status->value,
            'stats' => $run->stats,
            'started_at' => $run->startedAt,
            'finished_at' => $run->finishedAt,
        ]);
    }

    public function findLatest(string $level, int $limit): array
    {
        $rows = ReconciliationRunRecord::query()
            ->toBase()
            ->where('level', $level)
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->limit(max(1, $limit))
            ->get()
            ->map(static fn (object $row): array => (array) $row)
            ->all();

        $runs = [];
        foreach (ReconciliationRunRecord::hydrate($rows) as $record) {
            $runs[] = $this->toDomain($record);
        }

        return $runs;
    }

    private function toDomain(ReconciliationRunRecord $record): ReconciliationRun
    {
        return new ReconciliationRun(
            id: $record->id,
            projectId: $record->project_id,
            providerCode: $record->provider_code,
            level: ReconciliationRunLevel::from($record->level),
            windowStart: $record->window_start === null ? null : DateTimeImmutable::createFromInterface($record->window_start),
            windowEnd: $record->window_end === null ? null : DateTimeImmutable::createFromInterface($record->window_end),
            status: ReconciliationRunStatus::from($record->status),
            stats: $record->stats,
            startedAt: DateTimeImmutable::createFromInterface($record->started_at),
            finishedAt: $record->finished_at === null ? null : DateTimeImmutable::createFromInterface($record->finished_at),
        );
    }
}
