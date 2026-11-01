<?php

declare(strict_types=1);

namespace Core\Identity\Application\UseCase;

use Core\Identity\Contracts\ListOperators;
use Core\Identity\Contracts\ListOperatorsCommand;
use Core\Identity\Contracts\OperatorPage;
use Core\Identity\Contracts\OperatorSummaryView;
use Core\Identity\Domain\Model\Operator;
use Core\Identity\Domain\Port\OperatorRepository;
use Core\Identity\Domain\Port\OrganizationMembershipRepository;
use Core\Support\Access\AccessScope;

final readonly class ListOperatorsUseCase implements ListOperators
{
    public function __construct(
        private OperatorRepository $operators,
        private OrganizationMembershipRepository $memberships,
    ) {}

    public function list(ListOperatorsCommand $command, AccessScope $scope): OperatorPage
    {
        // ADR-0069 §2 — a **platform role** sees every operator; an
        // account with only organization memberships sees the operators
        // that belong to the organizations it belongs to, and nobody
        // else's. `organizationFilter()` returns null for a platform scope
        // (no restriction at all) and the list of the account's
        // organizations otherwise — empty when it belongs to none, which
        // must yield an empty listing, never every operator.
        $organizationIds = $scope->organizationFilter();

        // The membership table is the source of truth for "who belongs to
        // which organization" (ADR-0069 §2) — the operator listing is not
        // filtered on a denormalized column that could drift from it.
        $operatorIds = $organizationIds === null
            ? null
            : $this->memberships->findActiveOperatorIdsByOrganizations($organizationIds);

        // The filter is applied in the query: the operators outside the
        // scope are never materialised, and the paginated `total` counts
        // the scoped rows only.
        $result = $this->operators->findAll($command->page, $command->perPage, $command->search, $operatorIds);

        $items = array_map(
            static fn (Operator $operator): OperatorSummaryView => new OperatorSummaryView(
                id: $operator->id,
                email: $operator->email,
                name: $operator->name,
                role: $operator->role?->value,
                mfaEnabled: $operator->mfaEnabled(),
                revoked: $operator->isRevoked(),
                createdAt: $operator->createdAt,
            ),
            $result['items'],
        );

        return new OperatorPage($items, $result['total'], $command->page, $command->perPage);
    }
}
