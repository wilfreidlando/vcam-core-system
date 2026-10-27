<?php

declare(strict_types=1);

namespace Core\Billing\Application\UseCase;

use Core\Billing\Application\Port\CustomerDirectory;
use Core\Billing\Application\Support\CustomerViews;
use Core\Billing\Contracts\CustomerPage;
use Core\Billing\Contracts\CustomerView;
use Core\Billing\Contracts\ListCustomers;
use Core\Billing\Contracts\ListCustomersCommand;
use Core\Billing\Domain\Model\Customer;
use Core\Support\Access\AccessScope;

/**
 * ADR-0070 §2 — `GET /v1/customers?cursor=&limit=`: the customers of one
 * project, page after page.
 *
 * The scope is a **required** parameter (ADR-0069 §2): the listing never
 * crosses the projects the console account may see, and an explicit
 * project outside the scope yields an empty page rather than another
 * tenant's customers.
 */
final readonly class ListCustomersUseCase implements ListCustomers
{
    public function __construct(
        private CustomerDirectory $customers,
    ) {}

    public function list(ListCustomersCommand $command, AccessScope $scope): CustomerPage
    {
        if (! $scope->allowsProject($command->projectId)) {
            return new CustomerPage([]);
        }

        $page = $this->customers->listByProject(
            $command->projectId,
            $command->search,
            $command->cursor,
            $command->limit,
        );

        $items = array_map(
            static fn (Customer $customer): CustomerView => CustomerViews::from($customer),
            $page['items'],
        );

        return new CustomerPage($items, $page['nextCursor']);
    }
}
