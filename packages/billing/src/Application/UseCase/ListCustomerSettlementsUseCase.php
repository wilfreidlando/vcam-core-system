<?php

declare(strict_types=1);

namespace Core\Billing\Application\UseCase;

use Core\Billing\Application\Support\CustomerSettlementViews;
use Core\Billing\Contracts\CustomerSettlementPage;
use Core\Billing\Contracts\CustomerSettlementView;
use Core\Billing\Contracts\ListCustomerSettlements;
use Core\Billing\Contracts\ListCustomerSettlementsCommand;
use Core\Billing\Domain\Model\CustomerSettlement;
use Core\Billing\Domain\Port\CustomerRepository;
use Core\Billing\Domain\Port\CustomerSettlementRepository;
use Core\Support\Access\AccessScope;

/**
 * ADR-0073 §2, §4 — the settlements of a customer: the online attempts
 * and the offline receipts of its invoices, in one chronology.
 *
 * The scope is a **required** parameter (ADR-0069 §2): the listing
 * never crosses the projects the console account may see, and an
 * explicit project outside the scope yields an empty page rather than
 * another tenant's settlements.
 */
final readonly class ListCustomerSettlementsUseCase implements ListCustomerSettlements
{
    public function __construct(
        private CustomerRepository $customers,
        private CustomerSettlementRepository $settlements,
    ) {}

    public function list(ListCustomerSettlementsCommand $command, AccessScope $scope): CustomerSettlementPage
    {
        if (! $scope->allowsProject($command->projectId)) {
            return new CustomerSettlementPage([]);
        }

        // The reference is the project's own key: it is resolved to the
        // customer it names, and a reference nobody answers yields an
        // empty page — never another tenant's settlements.
        $customer = $this->customers->findByExternalRef($command->projectId, $command->customerRef);

        if ($customer === null) {
            return new CustomerSettlementPage([]);
        }

        $page = $this->settlements->listByCustomer(
            $command->projectId,
            $customer->id,
            $command->cursor,
            $command->limit,
        );

        $items = array_map(
            fn (CustomerSettlement $settlement): CustomerSettlementView => CustomerSettlementViews::from($settlement),
            $page['items'],
        );

        return new CustomerSettlementPage($items, $page['nextCursor']);
    }
}
