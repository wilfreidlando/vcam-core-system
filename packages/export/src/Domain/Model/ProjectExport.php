<?php

declare(strict_types=1);

namespace Core\Export\Domain\Model;

use DateTimeImmutable;

/**
 * One export of a project (ADR-0088 §3).
 *
 * It lives `queued → running → done | failed`. A `done` export holds a file
 * until `expiresAt`; after the purge the record stays and the file is gone.
 */
final class ProjectExport
{
    /** ADR-0088 §3 — the file is deleted after seven days. */
    public const RETENTION_DAYS = 7;

    public function __construct(
        public readonly string $id,
        public readonly string $projectId,
        public ExportStatus $status,
        public readonly string $requestedBy,
        public readonly DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $finishedAt,
        public readonly DateTimeImmutable $expiresAt,
        public ?string $filePath,
        public ?string $error,
    ) {}

    public static function queue(string $id, string $projectId, string $requestedBy, DateTimeImmutable $now): self
    {
        return new self(
            $id,
            $projectId,
            ExportStatus::Queued,
            $requestedBy,
            $now,
            null,
            $now->modify('+'.self::RETENTION_DAYS.' days'),
            null,
            null,
        );
    }

    public function markRunning(): void
    {
        $this->status = ExportStatus::Running;
    }

    public function markDone(string $filePath, DateTimeImmutable $now): void
    {
        $this->status = ExportStatus::Done;
        $this->filePath = $filePath;
        $this->finishedAt = $now;
        $this->error = null;
    }

    public function markFailed(string $error, DateTimeImmutable $now): void
    {
        $this->status = ExportStatus::Failed;
        $this->finishedAt = $now;
        $this->error = $error;
    }

    /**
     * The file was deleted: the record stays, the download is over.
     */
    public function discardFile(): void
    {
        $this->filePath = null;
    }

    /**
     * Can it still be downloaded?
     */
    public function isAvailable(DateTimeImmutable $now): bool
    {
        return $this->status === ExportStatus::Done && $this->filePath !== null && $now < $this->expiresAt;
    }
}
