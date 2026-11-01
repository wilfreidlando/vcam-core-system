<?php

declare(strict_types=1);

namespace Core\Export\Application\UseCase;

use Core\Export\Contracts\ExportView;
use Core\Export\Domain\Model\ProjectExport;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * The one mapping from an export to its read model.
 */
final class ExportViews
{
    public static function from(ProjectExport $export, DateTimeImmutable $now): ExportView
    {
        return new ExportView(
            id: $export->id,
            projectId: $export->projectId,
            status: $export->status->value,
            requestedBy: $export->requestedBy,
            createdAt: $export->createdAt->format(DateTimeInterface::RFC3339),
            finishedAt: $export->finishedAt?->format(DateTimeInterface::RFC3339),
            expiresAt: $export->expiresAt->format(DateTimeInterface::RFC3339),
            error: $export->error,
            available: $export->isAvailable($now),
        );
    }
}
