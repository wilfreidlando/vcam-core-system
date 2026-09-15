<?php

declare(strict_types=1);

namespace Core\Admin\Presentation\Http\Controllers;

use Core\Audit\Contracts\AuditQuery;
use Core\Audit\Contracts\AuditSearchCriteria;
use Core\Identity\Contracts\OperatorContext;
use Core\Support\Http\ProblemDetails;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `admin` reaches `audit` only through its `Contracts\AuditQuery` port
 * (ADR-0044, ADR-0027) — never raw Eloquent, even though this module is
 * licensed to depend on every other one.
 */
final class SearchAuditLogController
{
    public function __construct(
        private readonly AuditQuery $auditQuery,
    ) {}

    /**
     * Search the audit log.
     *
     * Filters: `resource`, `actor`, `action`, `correlation_id`, `from`/`to`
     * (ISO 8601 or `Y-m-d`), `page`/`per_page`. Requires `audit:read`.
     */
    public function __invoke(Request $request, OperatorContext $operator): JsonResponse|Response
    {
        if (! $operator->hasPermission('audit:read')) {
            return ProblemDetails::forbidden('The audit:read permission is required.');
        }

        $page = max(1, (int) $request->query('page', '1'));
        $perPage = min(100, max(1, (int) $request->query('per_page', '25')));

        $from = $this->dateQuery($request, 'from');
        $to = $this->dateQuery($request, 'to');

        $result = $this->auditQuery->search(new AuditSearchCriteria(
            resource: $this->stringQuery($request, 'resource'),
            actor: $this->stringQuery($request, 'actor'),
            action: $this->stringQuery($request, 'action'),
            correlationId: $this->stringQuery($request, 'correlation_id'),
            from: $from,
            to: $to,
            page: $page,
            perPage: $perPage,
        ));

        return new JsonResponse([
            'data' => array_map(static fn ($entry): array => [
                'id' => $entry->id,
                'actor' => $entry->actor,
                'action' => $entry->action,
                'resource' => $entry->resource,
                'before' => $entry->before,
                'after' => $entry->after,
                'context' => $entry->context,
                'correlation_id' => $entry->correlationId,
                'occurred_at' => $entry->occurredAt->format(DATE_ATOM),
            ], $result->items),
            'meta' => [
                'page' => $result->page,
                'per_page' => $result->perPage,
                'total' => $result->total,
            ],
        ]);
    }

    private function stringQuery(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function dateQuery(Request $request, string $key): ?DateTimeImmutable
    {
        $value = $this->stringQuery($request, $key);
        if ($value === null) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat(DATE_ATOM, $value);
        if ($date === false) {
            $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);
        }

        return $date === false ? null : $date;
    }
}
