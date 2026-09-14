<?php

declare(strict_types=1);

namespace Core\Audit\Infrastructure\Persistence\Eloquent;

use Core\Audit\Contracts\AuditEntry;
use Core\Audit\Contracts\AuditLogger;

/**
 * The log is append-only by convention, not by a database privilege yet: no
 * code path in this codebase ever updates or deletes an `audit_log` row.
 * Revisit with a restricted PostgreSQL role if that guarantee needs to be
 * structural rather than conventional.
 */
final class EloquentAuditLogger implements AuditLogger
{
    public function record(AuditEntry $entry): void
    {
        AuditLogRecord::query()->create([
            'actor' => $entry->actor,
            'action' => $entry->action,
            'resource' => $entry->resource,
            'before' => $entry->before,
            'after' => $entry->after,
            'context' => $entry->context,
            'correlation_id' => $entry->correlationId,
            'created_at' => $entry->occurredAt,
        ]);
    }
}
