<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

use InvalidArgumentException;

/**
 * ADR-0088 §3 — the billing objects of a project, in the shape of the
 * **public API**, page after page, for the project export.
 *
 * The export module may not know the shape of a customer or of an invoice
 * (G3c): billing hands over the rows exactly as `GET /v1/customers`,
 * `/v1/invoices`… serialise them, so the export and the API cannot drift.
 */
interface BillingExportRecords
{
    /**
     * The types of object this reader exports, in export order.
     *
     * @return list<string>
     */
    public function types(): array;

    /**
     * @return array{rows: list<array<string, mixed>>, next_cursor: string|null}
     *
     * @throws InvalidArgumentException the type is not one of `types()`
     */
    public function page(string $type, string $projectId, ?string $cursor, int $limit): array;
}
