<?php

declare(strict_types=1);

namespace Core\Billing\Presentation\Http\Controllers\Concerns;

use Illuminate\Http\Request;

/**
 * The query parameters of the cursor listings (ADR-0088 §2): an opaque
 * `cursor`, a `limit` of at most a hundred items, and the simple
 * indexed filters a project may narrow its listing by.
 *
 * The cursor is opaque and travels back and forth untouched — the
 * caller never builds one.
 */
trait ReadsListParameters
{
    /**
     * ADR-0088 §2 — at most a hundred items per page, fifty by default.
     */
    protected function listLimit(Request $request): int
    {
        $raw = $request->query('limit');
        $limit = is_numeric($raw) ? (int) $raw : 50;

        return max(1, min(100, $limit));
    }

    /**
     * The opaque cursor of the next page — `null` on the first page.
     */
    protected function listCursor(Request $request): ?string
    {
        return $this->stringQuery($request, 'cursor');
    }

    /**
     * An optional filter — an absent or empty parameter is no filter.
     */
    protected function stringQuery(Request $request, string $key): ?string
    {
        $raw = $request->query($key);

        return is_string($raw) && $raw !== '' ? $raw : null;
    }
}
