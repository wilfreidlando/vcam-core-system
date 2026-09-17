<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use Core\Audit\Contracts\AuditQuery;
use DateTimeImmutable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Audit log search + view. The form has five free-text fields
 * (resource, actor, action, from, to). Empty fields are simply not
 * passed to the search criteria — the audit module treats them as
 * "no filter".
 */
final class AuditLogController
{
    public function __construct(
        private readonly AuditQuery $audit,
    ) {}

    public function index(Request $request): Response
    {
        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));
        $fromRaw = (string) $request->query('from', '');
        $toRaw = (string) $request->query('to', '');

        $from = $this->parseDate($fromRaw);
        $to = $this->parseDate($toRaw);

        $result = $this->audit->search(new \Core\Audit\Contracts\AuditSearchCriteria(
            resource: $this->optString($request->query('resource')),
            actor: $this->optString($request->query('actor')),
            action: $this->optString($request->query('action')),
            correlationId: $this->optString($request->query('correlation_id')),
            from: $from,
            to: $to,
            page: $page,
            perPage: $perPage,
        ));

        return response()->view('admin.audit-log.index', [
            'title' => 'Audit log',
            'entries' => $result->items,
            'total' => $result->total,
            'page' => $page,
            'perPage' => $perPage,
            'filters' => [
                'resource' => (string) $request->query('resource', ''),
                'actor' => (string) $request->query('actor', ''),
                'action' => (string) $request->query('action', ''),
                'from' => $fromRaw,
                'to' => $toRaw,
            ],
        ]);
    }

    private function parseDate(string $raw): ?DateTimeImmutable
    {
        if ($raw === '') {
            return null;
        }
        foreach (['Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s', DATE_ATOM, 'Y-m-d'] as $fmt) {
            $d = DateTimeImmutable::createFromFormat($fmt, $raw);
            if ($d !== false) {
                return $d;
            }
        }

        return null;
    }

    private function optString(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }
}
