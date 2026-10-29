<?php

declare(strict_types=1);

namespace Core\Billing\Application\UseCase;

use Core\Billing\Application\Port\UsageReader;
use Core\Billing\Contracts\ListUsageJournal;
use Core\Billing\Contracts\ListUsageJournalCommand;
use Core\Billing\Contracts\UsagePage;
use Core\Support\Access\AccessScope;

final readonly class ListUsageJournalUseCase implements ListUsageJournal
{
    private const MAX_LIMIT = 100;

    public function __construct(private UsageReader $reader) {}

    public function list(ListUsageJournalCommand $command, AccessScope $scope): UsagePage
    {
        if (! $scope->allowsProject($command->projectId)) {
            return new UsagePage([]);
        }

        return $this->reader->journal(
            $command->projectId,
            $command->meter,
            $command->customerRef,
            $command->createdFrom,
            $command->createdTo,
            $command->cursor,
            max(1, min(self::MAX_LIMIT, $command->limit)),
        );
    }
}
