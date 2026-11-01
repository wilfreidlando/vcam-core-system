<?php

declare(strict_types=1);

namespace Core\Export\Contracts;

/**
 * The read model of an export — never the Domain model (G3c).
 *
 * `$status` is the plain string of `queued|running|done|failed`.
 * `$available` says whether the file can still be downloaded: the export
 * is `done`, the file exists and its seven days are not over.
 */
final readonly class ExportView
{
    public function __construct(
        public string $id,
        public string $projectId,
        public string $status,
        public string $requestedBy,
        public string $createdAt,
        public ?string $finishedAt,
        public string $expiresAt,
        public ?string $error,
        public bool $available,
    ) {}
}
