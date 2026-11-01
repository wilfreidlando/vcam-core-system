<?php

declare(strict_types=1);

namespace Core\Ledger\Application\UseCase;

use Core\Ledger\Application\Support\LedgerAdjustmentViews;
use Core\Ledger\Contracts\LedgerAdjustmentPage;
use Core\Ledger\Contracts\LedgerAdjustmentView;
use Core\Ledger\Contracts\ListLedgerAdjustments;
use Core\Ledger\Contracts\ListLedgerAdjustmentsCommand;
use Core\Ledger\Domain\Model\LedgerAdjustment;
use Core\Ledger\Domain\Model\LedgerAdjustmentStatus;
use Core\Ledger\Domain\Port\LedgerAdjustmentRepository;
use Core\Support\Access\AccessScope;
use InvalidArgumentException;

/**
 * ADR-0076 §6 — the console's listing of the operating corrections.
 *
 * ADR-0069 §2 — the corrections of a project outside the account's scope
 * are neither listed nor counted, whatever the caller asked for.
 */
final readonly class ListLedgerAdjustmentsUseCase implements ListLedgerAdjustments
{
    private const MAX_PER_PAGE = 100;

    public function __construct(private LedgerAdjustmentRepository $adjustments) {}

    public function list(ListLedgerAdjustmentsCommand $command, AccessScope $scope): LedgerAdjustmentPage
    {
        $status = null;
        if ($command->status !== null) {
            $status = LedgerAdjustmentStatus::tryFrom($command->status)
                ?? throw new InvalidArgumentException(sprintf('Unknown ledger adjustment status "%s".', $command->status));
        }

        $page = max(1, $command->page);
        $perPage = max(1, min(self::MAX_PER_PAGE, $command->perPage));

        $projectIds = $this->restrict($command->projectIds, $scope);

        $result = $this->adjustments->list($projectIds, $status, $page, $perPage);

        return new LedgerAdjustmentPage(
            items: array_map(
                static fn (LedgerAdjustment $adjustment): LedgerAdjustmentView => LedgerAdjustmentViews::from($adjustment),
                $result['items'],
            ),
            total: $result['total'],
            page: $page,
            perPage: $perPage,
        );
    }

    public function find(string $adjustmentId, AccessScope $scope): ?LedgerAdjustmentView
    {
        $adjustment = $this->adjustments->find($adjustmentId);

        if ($adjustment === null) {
            return null;
        }

        // ADR-0069 §2 — a correction of another tenant is "not found".
        if (! $scope->allowsProject($adjustment->projectId)) {
            return null;
        }

        return LedgerAdjustmentViews::from($adjustment);
    }

    /**
     * The projects to read: the caller's choice, restricted to the scope.
     * `null` means "every project" — which only a platform scope may have.
     *
     * @param list<string>|null $requested
     * @return list<string>|null
     */
    private function restrict(?array $requested, AccessScope $scope): ?array
    {
        $allowed = $scope->projectFilter();

        if ($allowed === null) {
            return $requested;
        }

        if ($requested === null) {
            return $allowed;
        }

        return array_values(array_intersect($requested, $allowed));
    }
}
