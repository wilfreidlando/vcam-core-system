<?php

declare(strict_types=1);

namespace Core\Audit\Infrastructure\Persistence\Eloquent;

use Core\Audit\Contracts\AuditEntryView;
use Core\Audit\Contracts\AuditQuery;
use Core\Audit\Contracts\AuditSearchCriteria;
use Core\Audit\Contracts\AuditSearchResult;

final class EloquentAuditQuery implements AuditQuery
{
    public function search(AuditSearchCriteria $criteria): AuditSearchResult
    {
        $query = AuditLogRecord::query();

        if ($criteria->resource !== null) {
            $query->where('resource', $criteria->resource);
        }
        if ($criteria->actor !== null) {
            $query->where('actor', $criteria->actor);
        }
        if ($criteria->action !== null) {
            $query->where('action', $criteria->action);
        }
        if ($criteria->correlationId !== null) {
            $query->where('correlation_id', $criteria->correlationId);
        }
        if ($criteria->from !== null) {
            $query->where('created_at', '>=', $criteria->from);
        }
        if ($criteria->to !== null) {
            $query->where('created_at', '<=', $criteria->to);
        }

        // `orderBy`/`skip`/`take`/`count` are forwarded by Eloquent\Builder to
        // the underlying query builder rather than declared on it directly;
        // going through `toBase()` first avoids a Larastan false positive
        // ("dynamic call to static method") on those forwarded calls, and
        // `AuditLogRecord::hydrate()` restores the model casts afterwards.
        $base = $query->toBase();

        $total = (clone $base)->count();

        $base->orderBy('created_at', 'desc');
        $base->skip(($criteria->page - 1) * $criteria->perPage);
        $base->take($criteria->perPage);

        $rows = $base->get()->map(static fn (object $row): array => (array) $row)->all();

        $records = AuditLogRecord::hydrate($rows);

        $items = [];
        foreach ($records as $record) {
            $items[] = new AuditEntryView(
                id: $record->id,
                actor: $record->actor,
                action: $record->action,
                resource: $record->resource,
                before: $record->before,
                after: $record->after,
                context: $record->context,
                correlationId: $record->correlation_id,
                occurredAt: $record->created_at->toDateTimeImmutable(),
            );
        }

        return new AuditSearchResult($items, $total, $criteria->page, $criteria->perPage);
    }
}
