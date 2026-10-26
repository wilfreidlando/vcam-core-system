<?php

declare(strict_types=1);

namespace Core\Audit\Infrastructure\Persistence\Eloquent;

use Core\Audit\Contracts\AuditEntry;
use Core\Audit\Contracts\AuditLogger;
use Illuminate\Support\Facades\Context;

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
            'organization_id' => $entry->organizationId,
            'project_id' => $entry->projectId,
            'before' => $entry->before,
            'after' => $entry->after,
            'context' => $entry->context,
            // The caller's own correlation id when it has one, the
            // request's otherwise. A caller that does not carry one (the
            // console's use cases pass `null`) still gets an entry that
            // can be joined with the request's logs — the journal's
            // promise is "correlated end to end", and it was not kept.
            'correlation_id' => $entry->correlationId ?? Context::get('correlation_id'),
            'created_at' => $entry->occurredAt,
        ]);
    }
}
