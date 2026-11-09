<?php

declare(strict_types=1);

namespace Core\Billing;

use Core\Billing\Application\Port\CustomerDirectory;
use Core\Billing\Application\Port\InvoiceLedger;
use Core\Billing\Application\Port\MetricsReader;
use Core\Billing\Application\Port\MetricsSnapshotStore;
use Core\Billing\Application\Port\PlanDirectory;
use Core\Billing\Application\Port\PlanVersionDirectory;
use Core\Billing\Application\Port\SubscriptionDirectory;
use Core\Billing\Application\Port\SubscriptionNoticeLog;
use Core\Billing\Application\Port\UsageReader;
use Core\Billing\Application\UseCase\AllocateInvoicePaymentUseCase;
use Core\Billing\Application\UseCase\ApproveManualReceiptUseCase;
use Core\Billing\Application\UseCase\ArchivePlanUseCase;
use Core\Billing\Application\UseCase\CancelSubscriptionUseCase;
use Core\Billing\Application\UseCase\ChangeSubscriptionPlanUseCase;
use Core\Billing\Application\UseCase\ConfirmSubscriptionPeriodPaymentUseCase;
use Core\Billing\Application\UseCase\ConsumeCapabilityUsageUseCase;
use Core\Billing\Application\UseCase\ConsumeUsageUseCase;
use Core\Billing\Application\UseCase\CreateInvoiceUseCase;
use Core\Billing\Application\UseCase\CreatePlanUseCase;
use Core\Billing\Application\UseCase\CreatePlanVersionUseCase;
use Core\Billing\Application\UseCase\CreateSubscriptionUseCase;
use Core\Billing\Application\UseCase\EraseCustomerUseCase;
use Core\Billing\Application\UseCase\FailInvoicePaymentUseCase;
use Core\Billing\Application\UseCase\FindCustomerByContactUseCase;
use Core\Billing\Application\UseCase\GetCustomerUseCase;
use Core\Billing\Application\UseCase\GetEntitlementsUseCase;
use Core\Billing\Application\UseCase\GetInvoiceUseCase;
use Core\Billing\Application\UseCase\GetSubscriptionUseCase;
use Core\Billing\Application\UseCase\GrantComplimentaryPeriodUseCase;
use Core\Billing\Application\UseCase\IssueInvoiceUseCase;
use Core\Billing\Application\UseCase\KeepSubscriptionRenewingUseCase;
use Core\Billing\Application\UseCase\ListCustomerSettlementsUseCase;
use Core\Billing\Application\UseCase\ListCustomersUseCase;
use Core\Billing\Application\UseCase\ListInvoicePaymentsUseCase;
use Core\Billing\Application\UseCase\ListInvoicesUseCase;
use Core\Billing\Application\UseCase\ListManualReceiptsUseCase;
use Core\Billing\Application\UseCase\ListPlansUseCase;
use Core\Billing\Application\UseCase\ListSubscriptionPeriodsUseCase;
use Core\Billing\Application\UseCase\ListSubscriptionSupplementsUseCase;
use Core\Billing\Application\UseCase\ListSubscriptionsUseCase;
use Core\Billing\Application\UseCase\ListUsageJournalUseCase;
use Core\Billing\Application\UseCase\ListUsageUseCase;
use Core\Billing\Application\UseCase\ManageBillingSettingsUseCase;
use Core\Billing\Application\UseCase\MarkInvoiceUncollectibleUseCase;
use Core\Billing\Application\UseCase\MetricsHistoryUseCase;
use Core\Billing\Application\UseCase\PauseSubscriptionUseCase;
use Core\Billing\Application\UseCase\PayInvoiceUseCase;
use Core\Billing\Application\UseCase\ProjectMetricsUseCase;
use Core\Billing\Application\UseCase\PublishPlanVersionUseCase;
use Core\Billing\Application\UseCase\PurchaseSubscriptionSupplementPackUseCase;
use Core\Billing\Application\UseCase\PurchaseSubscriptionSupplementUseCase;
use Core\Billing\Application\UseCase\ReactivateCustomerUseCase;
use Core\Billing\Application\UseCase\RecordManualReceiptUseCase;
use Core\Billing\Application\UseCase\ResumeSubscriptionUseCase;
use Core\Billing\Application\UseCase\SuspendCustomerUseCase;
use Core\Billing\Application\UseCase\UpsertCustomerUseCase;
use Core\Billing\Application\UseCase\VoidInvoiceUseCase;
use Core\Billing\Contracts\ApproveManualReceipt;
use Core\Billing\Contracts\ArchivePlan;
use Core\Billing\Contracts\BillingExportRecords;
use Core\Billing\Contracts\CancelSubscription;
use Core\Billing\Contracts\ChangeSubscriptionPlan;
use Core\Billing\Contracts\ConfirmSubscriptionPeriodPayment;
use Core\Billing\Contracts\ConsumeCapabilityUsage;
use Core\Billing\Contracts\ConsumeUsage;
use Core\Billing\Contracts\CreateInvoice;
use Core\Billing\Contracts\CreatePlan;
use Core\Billing\Contracts\CreatePlanVersion;
use Core\Billing\Contracts\CreateSubscription;
use Core\Billing\Contracts\EraseCustomer;
use Core\Billing\Contracts\FindCustomerByContact;
use Core\Billing\Contracts\GetCustomer;
use Core\Billing\Contracts\GetEntitlements;
use Core\Billing\Contracts\GetInvoice;
use Core\Billing\Contracts\GetSubscription;
use Core\Billing\Contracts\GrantComplimentaryPeriod;
use Core\Billing\Contracts\IssueInvoice;
use Core\Billing\Contracts\KeepSubscriptionRenewing;
use Core\Billing\Contracts\ListCustomers;
use Core\Billing\Contracts\ListCustomerSettlements;
use Core\Billing\Contracts\ListInvoicePayments;
use Core\Billing\Contracts\ListInvoices;
use Core\Billing\Contracts\ListManualReceipts;
use Core\Billing\Contracts\ListPlans;
use Core\Billing\Contracts\ListSubscriptionPeriods;
use Core\Billing\Contracts\ListSubscriptions;
use Core\Billing\Contracts\ListSubscriptionSupplements;
use Core\Billing\Contracts\ListUsage;
use Core\Billing\Contracts\ListUsageJournal;
use Core\Billing\Contracts\ManageBillingSettings;
use Core\Billing\Contracts\MarkInvoiceUncollectible;
use Core\Billing\Contracts\MetricsHistory;
use Core\Billing\Contracts\PauseSubscription;
use Core\Billing\Contracts\PayInvoice;
use Core\Billing\Contracts\ProjectMetrics;
use Core\Billing\Contracts\PublishPlanVersion;
use Core\Billing\Contracts\PurchaseSubscriptionSupplement;
use Core\Billing\Contracts\PurchaseSubscriptionSupplementPack;
use Core\Billing\Contracts\ReactivateCustomer;
use Core\Billing\Contracts\RecordManualReceipt;
use Core\Billing\Contracts\ResumeSubscription;
use Core\Billing\Contracts\SuspendCustomer;
use Core\Billing\Contracts\UpsertCustomer;
use Core\Billing\Contracts\VoidInvoice;
use Core\Billing\Domain\Port\BillingSettingsRepository;
use Core\Billing\Domain\Port\CustomerRepository;
use Core\Billing\Domain\Port\CustomerSettlementRepository;
use Core\Billing\Domain\Port\InvoiceLineRepository;
use Core\Billing\Domain\Port\InvoiceNumberSequenceRepository;
use Core\Billing\Domain\Port\InvoicePaymentRepository;
use Core\Billing\Domain\Port\InvoiceRepository;
use Core\Billing\Domain\Port\ManualReceiptRepository;
use Core\Billing\Domain\Port\PlanRepository;
use Core\Billing\Domain\Port\PlanVersionRepository;
use Core\Billing\Domain\Port\SubscriptionPeriodRepository;
use Core\Billing\Domain\Port\SubscriptionRepository;
use Core\Billing\Domain\Port\SubscriptionSupplementRepository;
use Core\Billing\Domain\Port\UsageStore;
use Core\Billing\Infrastructure\Ledger\InvoiceLedgerWriter;
use Core\Billing\Infrastructure\Persistence\DatabaseMetricsReader;
use Core\Billing\Infrastructure\Persistence\DatabaseMetricsSnapshotStore;
use Core\Billing\Infrastructure\Persistence\DatabaseSubscriptionNoticeLog;
use Core\Billing\Infrastructure\Persistence\Eloquent\EloquentBillingSettingsRepository;
use Core\Billing\Infrastructure\Persistence\Eloquent\EloquentCustomerRepository;
use Core\Billing\Infrastructure\Persistence\Eloquent\EloquentCustomerSettlementRepository;
use Core\Billing\Infrastructure\Persistence\Eloquent\EloquentInvoiceLineRepository;
use Core\Billing\Infrastructure\Persistence\Eloquent\EloquentInvoiceNumberSequenceRepository;
use Core\Billing\Infrastructure\Persistence\Eloquent\EloquentInvoicePaymentRepository;
use Core\Billing\Infrastructure\Persistence\Eloquent\EloquentInvoiceRepository;
use Core\Billing\Infrastructure\Persistence\Eloquent\EloquentManualReceiptRepository;
use Core\Billing\Infrastructure\Persistence\Eloquent\EloquentPlanRepository;
use Core\Billing\Infrastructure\Persistence\Eloquent\EloquentPlanVersionRepository;
use Core\Billing\Infrastructure\Persistence\Eloquent\EloquentSubscriptionPeriodRepository;
use Core\Billing\Infrastructure\Persistence\Eloquent\EloquentSubscriptionRepository;
use Core\Billing\Infrastructure\Persistence\Eloquent\EloquentSubscriptionSupplementRepository;
use Core\Billing\Infrastructure\Persistence\Usage\DatabaseUsageReader;
use Core\Billing\Infrastructure\Persistence\Usage\PostgresUsageStore;
use Core\Billing\Presentation\Console\Commands\AdvanceSubscriptionsCommand;
use Core\Billing\Presentation\Console\Commands\SnapshotMetricsCommand;
use Core\Billing\Presentation\Export\BillingExportRecordsReader;
use Core\Billing\Presentation\Listeners\InvoicePaymentStatusListener;
use Core\Events\Contracts\OutboxEventPublished;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Composition root of the `billing` bounded context: binds Domain ports to
 * their Infrastructure adapters, binds the published Contracts to their use
 * cases, and registers this module's migrations, routes and commands.
 *
 * Responsibility: the customers of a project (ADR-0070), the versioned plan
 * catalogue (ADR-0071) and the subscription contracts with their periods
 * (ADR-0072).
 *
 * Delivery lot: Lot 10 — Commerce I.
 */
final class BillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Domain ports → Infrastructure adapters.
        $this->app->bind(CustomerRepository::class, EloquentCustomerRepository::class);
        $this->app->bind(CustomerSettlementRepository::class, EloquentCustomerSettlementRepository::class);
        $this->app->bind(PlanRepository::class, EloquentPlanRepository::class);
        $this->app->bind(PlanVersionRepository::class, EloquentPlanVersionRepository::class);
        $this->app->bind(SubscriptionRepository::class, EloquentSubscriptionRepository::class);
        // ADR-0117 §2 — the supplements of a contract.
        $this->app->bind(SubscriptionSupplementRepository::class, EloquentSubscriptionSupplementRepository::class);
        $this->app->bind(SubscriptionPeriodRepository::class, EloquentSubscriptionPeriodRepository::class);
        $this->app->bind(BillingSettingsRepository::class, EloquentBillingSettingsRepository::class);
        $this->app->bind(InvoiceRepository::class, EloquentInvoiceRepository::class);
        $this->app->bind(InvoiceLineRepository::class, EloquentInvoiceLineRepository::class);
        $this->app->bind(InvoiceNumberSequenceRepository::class, EloquentInvoiceNumberSequenceRepository::class);
        $this->app->bind(InvoicePaymentRepository::class, EloquentInvoicePaymentRepository::class);
        $this->app->bind(ManualReceiptRepository::class, EloquentManualReceiptRepository::class);
        $this->app->bind(UsageStore::class, PostgresUsageStore::class);
        $this->app->bind(UsageReader::class, DatabaseUsageReader::class);
        $this->app->bind(MetricsReader::class, DatabaseMetricsReader::class);
        $this->app->bind(MetricsSnapshotStore::class, DatabaseMetricsSnapshotStore::class);

        // The Application port of the ledger — `billing` says what
        // happened, the adapter says how it is booked (ADR-0073 §3).
        $this->app->bind(InvoiceLedger::class, InvoiceLedgerWriter::class);

        // The Application ports — the listing half of the repositories
        // (ADR-0088's cursor) — are served by the same adapters: the
        // listing is a persistence concern, the view is not.
        $this->app->bind(CustomerDirectory::class, EloquentCustomerRepository::class);
        $this->app->bind(PlanDirectory::class, EloquentPlanRepository::class);
        $this->app->bind(PlanVersionDirectory::class, EloquentPlanVersionRepository::class);
        $this->app->bind(SubscriptionDirectory::class, EloquentSubscriptionRepository::class);
        $this->app->bind(SubscriptionNoticeLog::class, DatabaseSubscriptionNoticeLog::class);

        // Published contracts → use cases.
        $this->app->bind(UpsertCustomer::class, UpsertCustomerUseCase::class);
        $this->app->bind(GetCustomer::class, GetCustomerUseCase::class);
        $this->app->bind(FindCustomerByContact::class, FindCustomerByContactUseCase::class);
        $this->app->bind(ListCustomers::class, ListCustomersUseCase::class);
        // ADR-0073 §2, §4 — what the customer paid, online and offline,
        // in one chronology.
        $this->app->bind(ListCustomerSettlements::class, ListCustomerSettlementsUseCase::class);
        $this->app->bind(SuspendCustomer::class, SuspendCustomerUseCase::class);
        $this->app->bind(ReactivateCustomer::class, ReactivateCustomerUseCase::class);
        $this->app->bind(EraseCustomer::class, EraseCustomerUseCase::class);
        $this->app->bind(CreatePlan::class, CreatePlanUseCase::class);
        $this->app->bind(ListPlans::class, ListPlansUseCase::class);
        $this->app->bind(CreatePlanVersion::class, CreatePlanVersionUseCase::class);
        $this->app->bind(PublishPlanVersion::class, PublishPlanVersionUseCase::class);
        $this->app->bind(ArchivePlan::class, ArchivePlanUseCase::class);
        $this->app->bind(CreateSubscription::class, CreateSubscriptionUseCase::class);
        $this->app->bind(GetSubscription::class, GetSubscriptionUseCase::class);
        $this->app->bind(ConsumeUsage::class, ConsumeUsageUseCase::class);
        $this->app->bind(ListUsage::class, ListUsageUseCase::class);
        $this->app->bind(ListUsageJournal::class, ListUsageJournalUseCase::class);
        // ADR-0088 §3 — the rows of the project export, in the public API's shape.
        $this->app->bind(BillingExportRecords::class, BillingExportRecordsReader::class);
        $this->app->bind(ProjectMetrics::class, ProjectMetricsUseCase::class);
        $this->app->bind(MetricsHistory::class, MetricsHistoryUseCase::class);
        $this->app->bind(ManageBillingSettings::class, ManageBillingSettingsUseCase::class);
        $this->app->bind(KeepSubscriptionRenewing::class, KeepSubscriptionRenewingUseCase::class);
        $this->app->bind(ConsumeCapabilityUsage::class, ConsumeCapabilityUsageUseCase::class);
        $this->app->bind(GetEntitlements::class, GetEntitlementsUseCase::class);
        $this->app->bind(ListSubscriptions::class, ListSubscriptionsUseCase::class);
        $this->app->bind(ListSubscriptionPeriods::class, ListSubscriptionPeriodsUseCase::class);
        $this->app->bind(CancelSubscription::class, CancelSubscriptionUseCase::class);
        $this->app->bind(ChangeSubscriptionPlan::class, ChangeSubscriptionPlanUseCase::class);
        $this->app->bind(PauseSubscription::class, PauseSubscriptionUseCase::class);
        $this->app->bind(ResumeSubscription::class, ResumeSubscriptionUseCase::class);
        $this->app->bind(GrantComplimentaryPeriod::class, GrantComplimentaryPeriodUseCase::class);
        $this->app->bind(ConfirmSubscriptionPeriodPayment::class, ConfirmSubscriptionPeriodPaymentUseCase::class);

        // ADR-0117 §4 — the purchase of units added on top of the limit
        // of a metered feature, and the history of those purchases.
        $this->app->bind(PurchaseSubscriptionSupplement::class, PurchaseSubscriptionSupplementUseCase::class);
        $this->app->bind(ListSubscriptionSupplements::class, ListSubscriptionSupplementsUseCase::class);

        // ADR-0119 §3 — l'achat d'un **pack** du catalogue : c'est le
        // geste du client final dans le portail, il cite un code et
        // jamais un prix.
        $this->app->bind(PurchaseSubscriptionSupplementPack::class, PurchaseSubscriptionSupplementPackUseCase::class);

        // The invoices — the document that carries the receivable
        // (ADR-0073, ADR-0094).
        $this->app->bind(CreateInvoice::class, CreateInvoiceUseCase::class);
        $this->app->bind(IssueInvoice::class, IssueInvoiceUseCase::class);
        $this->app->bind(VoidInvoice::class, VoidInvoiceUseCase::class);
        $this->app->bind(MarkInvoiceUncollectible::class, MarkInvoiceUncollectibleUseCase::class);
        $this->app->bind(GetInvoice::class, GetInvoiceUseCase::class);
        $this->app->bind(ListInvoices::class, ListInvoicesUseCase::class);

        // The collection of an invoice — the online attempt and the
        // offline receipt (ADR-0073 §2, §4; ADR-0094 §§8-13).
        $this->app->bind(PayInvoice::class, PayInvoiceUseCase::class);
        $this->app->bind(ListInvoicePayments::class, ListInvoicePaymentsUseCase::class);
        $this->app->bind(ListManualReceipts::class, ListManualReceiptsUseCase::class);
        $this->app->bind(RecordManualReceipt::class, RecordManualReceiptUseCase::class);
        $this->app->bind(ApproveManualReceipt::class, ApproveManualReceiptUseCase::class);

        // The two use cases the listener of the outbox calls. They are
        // bound so that the listener, resolved by the container, gets
        // the same instances the application would.
        $this->app->bind(AllocateInvoicePaymentUseCase::class);
        $this->app->bind(FailInvoicePaymentUseCase::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // ADR-0018 — a module owns its routes.
        Route::middleware('api')->group(__DIR__.'/../routes/api.php');

        // ADR-0073 §2 — `billing` learns that a payment settled by
        // consuming the `payment.status_changed` event of the outbox.
        // The relay is the only physical reader of the outbox
        // (ADR-0040 §1): the module subscribes to the republication.
        Event::listen(OutboxEventPublished::class, InvoicePaymentStatusListener::class);

        if ($this->app->runningInConsole()) {
            $this->commands([AdvanceSubscriptionsCommand::class, SnapshotMetricsCommand::class]);
        }
    }
}
