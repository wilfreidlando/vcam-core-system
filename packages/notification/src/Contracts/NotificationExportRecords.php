<?php

declare(strict_types=1);

namespace Core\Notification\Contracts;

/**
 * ADR-0088 §3 — the notifications of a project in the shape of the
 * **public API** (`GET /v1/notifications`), page after page, for the
 * project export. Neither the content nor the recipient is exported: the
 * listing does not carry them either.
 */
interface NotificationExportRecords
{
    /**
     * @return array{rows: list<array<string, mixed>>, next_cursor: string|null}
     */
    public function page(string $projectId, ?string $cursor, int $limit): array;
}
