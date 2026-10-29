<?php

declare(strict_types=1);

namespace Core\Payment\Contracts;

/**
 * ADR-0088 §3 — the payments of a project in the shape of the **public
 * API** (`GET /v1/payments`), page after page, for the project export.
 */
interface PaymentExportRecords
{
    /**
     * @return array{rows: list<array<string, mixed>>, next_cursor: string|null}
     */
    public function page(string $projectId, ?string $cursor, int $limit): array;
}
