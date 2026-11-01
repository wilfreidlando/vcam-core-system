<?php

declare(strict_types=1);

namespace Core\Billing\Presentation\Export;

use Core\Billing\Contracts\BillingExportRecords;
use Core\Billing\Contracts\ListCustomers;
use Core\Billing\Contracts\ListCustomersCommand;
use Core\Billing\Contracts\ListInvoices;
use Core\Billing\Contracts\ListInvoicesCommand;
use Core\Billing\Contracts\ListPlans;
use Core\Billing\Contracts\ListPlansCommand;
use Core\Billing\Contracts\ListSubscriptions;
use Core\Billing\Contracts\ListSubscriptionsCommand;
use Core\Billing\Contracts\ListUsageJournal;
use Core\Billing\Contracts\ListUsageJournalCommand;
use Core\Billing\Presentation\Http\Controllers\Concerns\SerializesBillingViews;
use Core\Support\Access\AccessScope;
use InvalidArgumentException;

/**
 * The billing rows of the project export, serialised by the very trait
 * the public controllers use (ADR-0088 §3).
 */
final class BillingExportRecordsReader implements BillingExportRecords
{
    use SerializesBillingViews;

    public function __construct(
        private readonly ListCustomers $customers,
        private readonly ListPlans $plans,
        private readonly ListSubscriptions $subscriptions,
        private readonly ListInvoices $invoices,
        private readonly ListUsageJournal $usage,
    ) {}

    public function types(): array
    {
        return ['customers', 'plans', 'subscriptions', 'invoices', 'usage'];
    }

    public function page(string $type, string $projectId, ?string $cursor, int $limit): array
    {
        $scope = AccessScope::forProject($projectId);

        switch ($type) {
            case 'customers':
                $page = $this->customers->list(new ListCustomersCommand($projectId, $cursor, $limit), $scope);
                $rows = array_map(fn ($view): array => $this->customerView($view, true), $page->items);
                break;
            case 'plans':
                $page = $this->plans->list(new ListPlansCommand(projectId: $projectId, cursor: $cursor, limit: $limit), $scope);
                $rows = array_map(fn ($view): array => $this->planView($view), $page->items);
                break;
            case 'subscriptions':
                $page = $this->subscriptions->list(new ListSubscriptionsCommand(projectId: $projectId, cursor: $cursor, limit: $limit), $scope);
                $rows = array_map(fn ($view): array => $this->subscriptionView($view), $page->items);
                break;
            case 'invoices':
                $page = $this->invoices->list(new ListInvoicesCommand(projectId: $projectId, cursor: $cursor, limit: $limit), $scope);
                $rows = array_map(fn ($view): array => $this->invoiceView($view), $page->items);
                break;
            case 'usage':
                $page = $this->usage->list(new ListUsageJournalCommand(projectId: $projectId, cursor: $cursor, limit: $limit), $scope);
                $rows = array_map(fn ($row): array => $this->usageRecord($row), $page->items);
                break;
            default:
                throw new InvalidArgumentException("Unknown billing export type '{$type}'.");
        }

        return ['rows' => $rows, 'next_cursor' => $page->nextCursor];
    }
}
