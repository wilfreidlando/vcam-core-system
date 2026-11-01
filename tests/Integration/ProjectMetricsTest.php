<?php

declare(strict_types=1);

namespace Tests\Integration;

use Core\Billing\Contracts\CreateInvoice;
use Core\Billing\Contracts\CreateInvoiceCommand;
use Core\Billing\Contracts\CreatePlan;
use Core\Billing\Contracts\CreatePlanCommand;
use Core\Billing\Contracts\CreatePlanVersion;
use Core\Billing\Contracts\CreatePlanVersionCommand;
use Core\Billing\Contracts\CreateSubscription;
use Core\Billing\Contracts\CreateSubscriptionCommand;
use Core\Billing\Contracts\InvoiceLineInput;
use Core\Billing\Contracts\IssueInvoice;
use Core\Billing\Contracts\IssueInvoiceCommand;
use Core\Billing\Contracts\ProjectMetrics;
use Core\Billing\Contracts\ProjectMetricsView;
use Core\Billing\Contracts\PublishPlanVersion;
use Core\Billing\Contracts\PublishPlanVersionCommand;
use Core\Billing\Contracts\RecordManualReceipt;
use Core\Billing\Contracts\RecordManualReceiptCommand;
use Core\Billing\Contracts\UpsertCustomer;
use Core\Billing\Contracts\UpsertCustomerCommand;
use Core\Billing\Contracts\VoidInvoice;
use Core\Billing\Contracts\VoidInvoiceCommand;
use Core\Billing\Domain\Model\BillingSettings;
use Core\Billing\Domain\Port\BillingSettingsRepository;
use Core\Support\Access\AccessScope;
use Core\Support\Clock;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ADR-0080 §2 — the indicators of a project, defined once, against a real
 * PostgreSQL. Time is injected: « now » is 2026-10-28T09:00:00Z, so « this
 * month » is October 2026.
 */
final class ProjectMetricsTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT = 'aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa';

    private const OTHER_PROJECT = 'bbbbbbbb-2222-4222-8222-bbbbbbbbbbbb';

    private const OPERATOR = '77777777-7777-4777-8777-777777777777';

    private const NOW = '2026-10-28T09:00:00Z';

    private int $sequence = 0;

    // ------------------------------------------------------------------ MRR

    #[Test]
    public function mrr_normalises_every_interval_to_a_month_rounding_half_up(): void
    {
        // day: 1 per 4 days = 7.5 / month  -> 8 (half up)
        $this->subscribeNew('c-day', 'p-day', 1, 'XAF', 'day', 4);
        // week: 7 per 4 weeks = 7.5 / month -> 8 (half up)
        $this->subscribeNew('c-week', 'p-week', 7, 'XAF', 'week', 4);
        // month: 5 per 2 months = 2.5 / month -> 3 (half up)
        $this->subscribeNew('c-month', 'p-month', 5, 'XAF', 'month', 2);
        // year: 18 per year = 1.5 / month -> 2 (half up)
        $this->subscribeNew('c-year', 'p-year', 18, 'XAF', 'year', 1);

        self::assertSame(8 + 8 + 3 + 2, $this->metrics()->mrrMinor['XAF']);
    }

    #[Test]
    public function each_interval_unit_has_its_own_normalisation_factor(): void
    {
        $this->subscribeNew('c-d', 'p-d', 100, 'XAF', 'day', 1);        // 100 x 30 = 3 000
        $this->subscribeNew('c-w', 'p-w', 1_000, 'EUR', 'week', 1);     // 1 000 x 30 / 7 = 4 285.71 -> 4 286
        $this->subscribeNew('c-m', 'p-m', 1_000, 'USD', 'month', 3);    // 1 000 / 3 = 333.33 -> 333
        $this->subscribeNew('c-y', 'p-y', 120_000, 'GBP', 'year', 1);   // 120 000 / 12 = 10 000

        $mrr = $this->metrics()->mrrMinor;

        self::assertSame(3_000, $mrr['XAF']);
        self::assertSame(4_286, $mrr['EUR']);
        self::assertSame(333, $mrr['USD']);
        self::assertSame(10_000, $mrr['GBP']);
    }

    #[Test]
    public function a_rounding_just_below_a_half_goes_down(): void
    {
        // 1 per 3 days = 10 / month exactly; 1 per 7 days = 4.29 -> 4; 1 per 9 weeks = 0.476 -> 0
        $this->subscribeNew('c-a', 'p-a', 1, 'XAF', 'day', 7);
        self::assertSame(4, $this->metrics()->mrrMinor['XAF']);
    }

    #[Test]
    public function mrr_mixes_intervals_in_one_currency_into_a_single_figure(): void
    {
        $this->subscribeNew('c-1', 'p-1', 25_000, 'XAF', 'month', 1);  // 25 000
        $this->subscribeNew('c-2', 'p-2', 120_000, 'XAF', 'year', 1);  // 10 000
        $this->subscribeNew('c-3', 'p-3', 7, 'XAF', 'week', 4);        // 8
        $this->subscribeNew('c-4', 'p-4', 1, 'XAF', 'day', 4);         // 8

        $view = $this->metrics();

        self::assertSame(['XAF' => 35_016], $view->mrrMinor);
    }

    #[Test]
    public function two_contracts_of_the_same_interval_are_summed_before_the_single_rounding(): void
    {
        // Same (currency, unit, count) group: (1 + 1 + 1 ... ) the group is rounded once.
        $this->subscribeNew('c-1', 'p-1', 1, 'XAF', 'year', 1);
        $this->subscribeNew('c-2', 'p-2', 5, 'XAF', 'year', 1);

        // 6 / 12 = 0.5 -> 1 (one rounding on the sum, not 0 + 0 nor 1 + 1)
        self::assertSame(1, $this->metrics()->mrrMinor['XAF']);
    }

    #[Test]
    public function mrr_counts_only_active_and_past_due_contracts(): void
    {
        $this->subscribeNew('c-active', 'p-1', 1_000, 'XAF', 'month', 1, 'active');
        $this->subscribeNew('c-past-due', 'p-2', 500, 'XAF', 'month', 1, 'past_due');
        $this->subscribeNew('c-trial', 'p-3', 9_000, 'XAF', 'month', 1, 'trialing');
        $this->subscribeNew('c-paused', 'p-4', 9_000, 'XAF', 'month', 1, 'paused');
        $this->subscribeNew('c-canceled', 'p-5', 9_000, 'XAF', 'month', 1, 'canceled');
        $this->subscribeNew('c-expired', 'p-6', 9_000, 'XAF', 'month', 1, 'expired');
        $this->subscribeNew('c-incomplete', 'p-7', 9_000, 'XAF', 'month', 1, 'incomplete');

        self::assertSame(['XAF' => 1_500], $this->metrics()->mrrMinor);
    }

    #[Test]
    public function currencies_are_reported_apart_and_never_summed(): void
    {
        $this->subscribeNew('c-xaf', 'p-xaf', 10_000, 'XAF', 'month', 1);
        $this->subscribeNew('c-eur', 'p-eur', 1_999, 'EUR', 'month', 1);

        $mrr = $this->metrics()->mrrMinor;

        self::assertSame(['EUR' => 1_999, 'XAF' => 10_000], $this->sorted($mrr));
        self::assertArrayNotHasKey('USD', $mrr);
    }

    #[Test]
    public function a_project_without_any_contract_has_an_empty_but_present_view(): void
    {
        $view = $this->metrics();

        self::assertSame(self::PROJECT, $view->projectId);
        self::assertSame([], $view->mrrMinor);
        self::assertSame([], $view->collectedMinor);
        self::assertSame([], $view->receivablesMinor);
        self::assertSame(0, $view->customers);
        self::assertSame(0, $view->activeCustomers);
        self::assertSame(0, $view->churnBps);
        self::assertSame('2026-10-28T09:00:00Z', $view->computedAt);
    }

    // ------------------------------------------------------------- counters

    #[Test]
    public function it_counts_trialing_past_due_and_paused_contracts(): void
    {
        $this->subscribeNew('c-1', 'p-1', 1_000, 'XAF', 'month', 1, 'trialing');
        $this->subscribeNew('c-2', 'p-2', 1_000, 'XAF', 'month', 1, 'trialing');
        $this->subscribeNew('c-3', 'p-3', 1_000, 'XAF', 'month', 1, 'past_due');
        $this->subscribeNew('c-4', 'p-4', 1_000, 'XAF', 'month', 1, 'paused');
        $this->subscribeNew('c-5', 'p-5', 1_000, 'XAF', 'month', 1, 'paused');
        $this->subscribeNew('c-6', 'p-6', 1_000, 'XAF', 'month', 1, 'paused');
        $this->subscribeNew('c-7', 'p-7', 1_000, 'XAF', 'month', 1, 'active');

        $view = $this->metrics();

        self::assertSame(2, $view->trialing);
        self::assertSame(1, $view->pastDue);
        self::assertSame(3, $view->paused);
    }

    #[Test]
    public function active_customers_are_those_with_a_trialing_active_or_past_due_contract(): void
    {
        $this->subscribeNew('c-trial', 'p-1', 1_000, 'XAF', 'month', 1, 'trialing');
        $this->subscribeNew('c-active', 'p-2', 1_000, 'XAF', 'month', 1, 'active');
        $this->subscribeNew('c-due', 'p-3', 1_000, 'XAF', 'month', 1, 'past_due');
        $this->subscribeNew('c-paused', 'p-4', 1_000, 'XAF', 'month', 1, 'paused');
        $this->subscribeNew('c-gone', 'p-5', 1_000, 'XAF', 'month', 1, 'canceled');
        $this->subscribeNew('c-wait', 'p-6', 1_000, 'XAF', 'month', 1, 'incomplete');
        $this->customer('c-never-subscribed');

        $view = $this->metrics();

        self::assertSame(3, $view->activeCustomers);
        self::assertSame(7, $view->customers);
    }

    #[Test]
    public function a_customer_with_two_contracts_is_one_active_customer(): void
    {
        $this->plan('p-1', 1_000, 'XAF', 'month', 1);
        $this->plan('p-2', 2_000, 'XAF', 'month', 1);
        $this->subscribe('twice', 'p-1', 'active');
        $this->subscribe('twice', 'p-2', 'past_due');

        $view = $this->metrics();

        self::assertSame(1, $view->customers);
        self::assertSame(1, $view->activeCustomers);
        self::assertSame(1, $view->pastDue);
        self::assertSame(['XAF' => 3_000], $view->mrrMinor, 'both contracts still bill');
    }

    // ------------------------------------------------------ new / lost / churn

    #[Test]
    public function new_and_lost_this_month_and_the_churn_in_basis_points(): void
    {
        $this->plan('p', 1_000, 'XAF', 'month', 1);

        // Alive when October opened: A, B, C.
        $this->subscribe('a', 'p', 'active', ['created_at' => '2026-09-01T08:00:00Z']);
        $this->subscribe('b', 'p', 'active', ['created_at' => '2026-09-10T08:00:00Z']);
        $this->subscribe('c', 'p', 'canceled', ['created_at' => '2026-09-15T08:00:00Z', 'canceled_at' => '2026-10-10T08:00:00Z', 'ended_at' => '2026-10-10T08:00:00Z']);
        // Lost long before the month: neither alive at the start nor lost in the month.
        $this->subscribe('d', 'p', 'canceled', ['created_at' => '2026-08-01T08:00:00Z', 'canceled_at' => '2026-09-05T08:00:00Z', 'ended_at' => '2026-09-05T08:00:00Z']);
        // Born in the month.
        $this->subscribe('e', 'p', 'active', ['created_at' => '2026-10-05T08:00:00Z']);
        // Born and lost within the month (expired): it never counted as a customer
        // when the month opened, so it is NOT churn (a sign-up that never paid must
        // not push the rate above what the base could lose).
        $this->subscribe('f', 'p', 'expired', ['created_at' => '2026-10-07T08:00:00Z', 'ended_at' => '2026-10-20T08:00:00Z']);

        $view = $this->metrics();

        self::assertSame(2, $view->newThisMonth, 'e and f were created in October');
        self::assertSame(1, $view->lostThisMonth, 'only c, which existed when October opened, was lost');
        // 1 lost / 3 alive at the start = 3 333 bps (integer division).
        self::assertSame(3_333, $view->churnBps);
    }

    #[Test]
    public function a_contract_created_on_the_first_instant_of_the_month_is_new_and_one_from_the_previous_month_end_is_not(): void
    {
        $this->plan('p', 1_000, 'XAF', 'month', 1);
        $this->subscribe('on-start', 'p', 'active', ['created_at' => '2026-10-01T00:00:00Z']);
        $this->subscribe('before', 'p', 'active', ['created_at' => '2026-09-30T23:59:59Z']);

        self::assertSame(1, $this->metrics()->newThisMonth);
    }

    #[Test]
    public function churn_is_zero_when_nothing_was_alive_at_the_start_of_the_month(): void
    {
        $this->plan('p', 1_000, 'XAF', 'month', 1);
        $this->subscribe('x', 'p', 'canceled', ['created_at' => '2026-10-05T08:00:00Z', 'canceled_at' => '2026-10-10T08:00:00Z', 'ended_at' => '2026-10-10T08:00:00Z']);

        $view = $this->metrics();

        self::assertSame(0, $view->lostThisMonth, 'created and ended in the same month: never part of the base');
        self::assertSame(0, $view->churnBps, 'a zero denominator is zero, not an error');
    }

    #[Test]
    public function a_full_loss_of_the_opening_base_is_ten_thousand_basis_points(): void
    {
        $this->plan('p', 1_000, 'XAF', 'month', 1);
        $this->subscribe('x', 'p', 'canceled', ['created_at' => '2026-09-05T08:00:00Z', 'canceled_at' => '2026-10-10T08:00:00Z', 'ended_at' => '2026-10-10T08:00:00Z']);

        self::assertSame(10_000, $this->metrics()->churnBps);
    }

    // ------------------------------------------- collected / receivables (ledger)

    #[Test]
    public function an_issued_but_unpaid_invoice_is_a_receivable_and_not_a_collection(): void
    {
        $this->customer('acme');
        $this->invoice('acme', 50_000);

        $view = $this->metrics();

        self::assertSame(['XAF' => 50_000], $view->receivablesMinor);
        self::assertSame(0, $view->collectedMinor['XAF'] ?? 0);
        self::assertSame([], $view->collectedMinor);
    }

    #[Test]
    public function an_offline_receipt_moves_money_from_receivables_to_collected(): void
    {
        $this->customer('acme');
        $invoiceId = $this->invoice('acme', 50_000);

        $before = $this->metrics();
        $this->receipt($invoiceId, 20_000);
        $partial = $this->metrics();
        $this->receipt($invoiceId, 30_000);
        $settled = $this->metrics();

        self::assertSame(50_000, $before->receivablesMinor['XAF']);
        self::assertSame(0, $before->collectedMinor['XAF'] ?? 0);

        self::assertSame(20_000, $partial->collectedMinor['XAF']);
        self::assertSame(30_000, $partial->receivablesMinor['XAF']);
        self::assertLessThan($before->receivablesMinor['XAF'], $partial->receivablesMinor['XAF']);

        self::assertSame(50_000, $settled->collectedMinor['XAF']);
        self::assertArrayNotHasKey('XAF', $settled->receivablesMinor, 'a settled receivable is not listed with a zero');
    }

    #[Test]
    public function collected_and_receivables_are_read_from_the_ledger_not_from_the_invoice_counters(): void
    {
        $this->customer('acme');
        $invoiceId = $this->invoice('acme', 50_000);

        // Tampering with the application counter does not change what the books say.
        DB::table('invoices')->where('id', $invoiceId)->update(['amount_paid_minor' => 50_000]);

        $view = $this->metrics();

        self::assertSame(['XAF' => 50_000], $view->receivablesMinor);
        self::assertSame([], $view->collectedMinor);
    }

    #[Test]
    public function collected_and_receivables_are_separated_per_currency(): void
    {
        $this->customer('acme');
        $xaf = $this->invoice('acme', 50_000, null, 'XAF');
        $eur = $this->invoice('acme', 1_250, null, 'EUR');
        $this->receipt($xaf, 10_000, 'XAF');
        $this->receipt($eur, 250, 'EUR');

        $view = $this->metrics();

        self::assertSame(['EUR' => 250, 'XAF' => 10_000], $this->sorted($view->collectedMinor));
        self::assertSame(['EUR' => 1_000, 'XAF' => 40_000], $this->sorted($view->receivablesMinor));
    }

    #[Test]
    public function the_ledger_of_another_project_never_leaks_in(): void
    {
        $this->customer('acme');
        $this->invoice('acme', 50_000);
        $this->customer('other', self::OTHER_PROJECT);
        $other = $this->invoice('other', 900_000, null, 'XAF', self::OTHER_PROJECT);
        $this->receipt($other, 100_000, 'XAF', self::OTHER_PROJECT);

        $view = $this->metrics();

        self::assertSame(['XAF' => 50_000], $view->receivablesMinor);
        self::assertSame([], $view->collectedMinor);
        self::assertSame(1, $view->customers);
    }

    // ------------------------------------------------------------------ aging

    #[Test]
    public function receivables_are_bucketed_by_days_past_due_with_exact_boundaries(): void
    {
        $this->customer('acme');
        $now = new DateTimeImmutable(self::NOW);
        $due = static fn (string $modifier): string => $now->modify($modifier)->format('Y-m-d\TH:i:s\Z');

        // current: due now (inclusive), due in the future, and no due date at all
        $this->invoice('acme', 1, $due('+0 seconds'));
        $this->invoice('acme', 2, $due('+10 days'));
        $this->invoice('acme', 4, null);
        // 1-30: one second late, and exactly 30 days late (inclusive)
        $this->invoice('acme', 8, $due('-1 second'));
        $this->invoice('acme', 16, $due('-30 days'));
        // 31-60: 30 days and one second late, and exactly 60 days late (inclusive)
        $this->invoice('acme', 32, $due('-30 days -1 second'));
        $this->invoice('acme', 64, $due('-60 days'));
        // 60+: 60 days and one second late, and a very old one
        $this->invoice('acme', 128, $due('-60 days -1 second'));
        $this->invoice('acme', 256, $due('-400 days'));

        $aging = $this->metrics()->receivableAging['XAF'];

        self::assertSame(['current' => 1 + 2 + 4, 'd1_30' => 8 + 16, 'd31_60' => 32 + 64, 'd60_plus' => 128 + 256], $aging);
    }

    #[Test]
    public function aging_counts_what_remains_not_the_full_total_and_skips_settled_draft_and_void_invoices(): void
    {
        $this->customer('acme');
        $now = new DateTimeImmutable(self::NOW);
        $old = $now->modify('-40 days')->format('Y-m-d\TH:i:s\Z');

        $partly = $this->invoice('acme', 50_000, $old);
        $this->receipt($partly, 20_000);

        $paid = $this->invoice('acme', 7_000, $old);
        $this->receipt($paid, 7_000);

        $voided = $this->invoice('acme', 9_000, $old);
        app(VoidInvoice::class)->void(new VoidInvoiceCommand(self::PROJECT, $voided, 'erreur', 'operator:'.self::OPERATOR));

        // A draft is not a receivable.
        app(CreateInvoice::class)->create(new CreateInvoiceCommand(
            projectId: self::PROJECT, customerRef: 'acme',
            lines: [new InvoiceLineInput(description: 'Brouillon', quantity: 1, unitAmountMinor: 3_000)],
            dueAt: $old, actor: 'test:billing',
        ));

        $view = $this->metrics();

        self::assertSame(['current' => 0, 'd1_30' => 0, 'd31_60' => 30_000, 'd60_plus' => 0], $view->receivableAging['XAF']);
        self::assertSame(['XAF' => 30_000], $view->receivablesMinor, 'the ledger agrees with the aging');
    }

    #[Test]
    public function aging_is_kept_per_currency(): void
    {
        $this->customer('acme');
        $old = (new DateTimeImmutable(self::NOW))->modify('-45 days')->format('Y-m-d\TH:i:s\Z');
        $this->invoice('acme', 1_000, $old, 'XAF');
        $this->invoice('acme', 20, $old, 'EUR');

        $aging = $this->metrics()->receivableAging;

        self::assertSame(1_000, $aging['XAF']['d31_60']);
        self::assertSame(20, $aging['EUR']['d31_60']);
    }

    // ------------------------------------------------------------------ usage

    #[Test]
    public function usage_pressure_counts_customers_at_eighty_percent_and_at_their_limit(): void
    {
        $now = new DateTimeImmutable(self::NOW);
        $open = $now->modify('+10 days')->format('Y-m-d\TH:i:s\Z');

        $this->counter('c1', 'students', 80, 100, $open);   // exactly 80 %: over80
        $this->counter('c2', 'students', 79, 100, $open);   // 79 %: neither
        $this->counter('c3', 'students', 100, 100, $open);  // at the limit: both
        $this->counter('c4', 'students', 150, 100, $open);  // beyond the limit: both
        $this->counter('c6', 'seats', 90, 100, null);       // gauge without window: over80
        $this->counter('c7', 'students', 100, 100, $open);  // two meters at the limit: counted once
        $this->counter('c7', 'seats', 100, 100, null);
        $this->counter('c8', 'students', 5, 0, $open);      // limit 0: ignored
        $this->counter('c9', 'students', 100, null, $open); // no limit: ignored

        $view = $this->metrics();

        self::assertSame(5, $view->customersOverEightyPercent, 'c1, c3, c4, c6, c7');
        self::assertSame(3, $view->customersAtLimit, 'c3, c4, c7');
    }

    #[Test]
    public function a_counter_whose_window_already_closed_is_not_counted(): void
    {
        $now = new DateTimeImmutable(self::NOW);

        $this->counter('closed', 'students', 100, 100, $now->modify('-1 day')->format('Y-m-d\TH:i:s\Z'), $now->modify('-31 days')->format('Y-m-d\TH:i:s\Z'));
        $this->counter('ends-now', 'students', 100, 100, self::NOW, $now->modify('-30 days')->format('Y-m-d\TH:i:s\Z'));
        $this->counter('open', 'students', 100, 100, $now->modify('+1 second')->format('Y-m-d\TH:i:s\Z'));

        $view = $this->metrics();

        self::assertSame(1, $view->customersAtLimit, 'only the counter still open');
        self::assertSame(1, $view->customersOverEightyPercent);
    }

    #[Test]
    public function a_counter_attributed_to_the_project_or_to_another_project_is_not_a_customer_pressure(): void
    {
        $open = (new DateTimeImmutable(self::NOW))->modify('+10 days')->format('Y-m-d\TH:i:s\Z');

        DB::table('usage_counters')->insert([
            'id' => (string) Str::uuid(), 'project_id' => self::PROJECT, 'owner' => '-', 'customer_id' => null, 'meter' => 'api',
            'window_start' => '2026-10-01T00:00:00Z', 'window_end' => $open, 'limit_value' => 10, 'used' => 10,
        ]);
        $this->counter('foreign', 'students', 100, 100, $open, null, self::OTHER_PROJECT);

        $view = $this->metrics();

        self::assertSame(0, $view->customersAtLimit);
        self::assertSame(0, $view->customersOverEightyPercent);
    }

    // ------------------------------------------------------------------ scope

    #[Test]
    public function a_scope_for_another_project_returns_null_never_a_zero_filled_view(): void
    {
        $this->subscribeNew('c-1', 'p-1', 1_000, 'XAF', 'month', 1);

        $metrics = app(ProjectMetrics::class);

        self::assertNull($metrics->forProject(self::PROJECT, AccessScope::forProject(self::OTHER_PROJECT)));
        self::assertNull($metrics->forProject(self::PROJECT, AccessScope::none()));
        self::assertNull($metrics->forProject(self::OTHER_PROJECT, AccessScope::forProject(self::PROJECT)));
    }

    #[Test]
    public function a_scope_that_allows_the_project_or_the_whole_platform_gets_the_figures(): void
    {
        $this->subscribeNew('c-1', 'p-1', 1_000, 'XAF', 'month', 1);

        $metrics = app(ProjectMetrics::class);

        $own = $metrics->forProject(self::PROJECT, AccessScope::forProject(self::PROJECT));
        $platform = $metrics->forProject(self::PROJECT, AccessScope::platform());

        self::assertInstanceOf(ProjectMetricsView::class, $own);
        self::assertInstanceOf(ProjectMetricsView::class, $platform);
        self::assertSame(['XAF' => 1_000], $own->mrrMinor);
        self::assertSame($own->mrrMinor, $platform->mrrMinor);
    }

    #[Test]
    public function figures_of_another_project_are_not_mixed_into_the_view(): void
    {
        $this->subscribeNew('mine', 'p-1', 1_000, 'XAF', 'month', 1);
        $this->subscribeNew('theirs', 'p-1', 77_000, 'XAF', 'month', 1, 'active', self::OTHER_PROJECT);

        $mine = $this->metrics();
        $theirs = app(ProjectMetrics::class)->forProject(self::OTHER_PROJECT, AccessScope::forProject(self::OTHER_PROJECT));

        self::assertSame(['XAF' => 1_000], $mine->mrrMinor);
        self::assertSame(1, $mine->customers);
        self::assertNotNull($theirs);
        self::assertSame(['XAF' => 77_000], $theirs->mrrMinor);
    }

    // ----------------------------------------------------------------- set up

    protected function setUp(): void
    {
        parent::setUp();

        // ADR-0087 — the time is injected.
        $clock = new class(new DateTimeImmutable(self::NOW)) implements Clock
        {
            public function __construct(public DateTimeImmutable $moment) {}

            public function now(): DateTimeImmutable
            {
                return $this->moment;
            }
        };
        $this->app->bind(Clock::class, static fn (): Clock => $clock);

        // Only XAF and USD are seeded; the multi-currency scenarios need two more.
        DB::table('currencies')->insertOrIgnore([['code' => 'EUR', 'exponent' => 2], ['code' => 'GBP', 'exponent' => 2]]);

        // Every receipt is accounted at the recording (no validation step).
        foreach ([self::PROJECT, self::OTHER_PROJECT] as $project) {
            $repository = app(BillingSettingsRepository::class);
            $settings = $repository->find($project);
            $repository->save(new BillingSettings(
                projectId: $settings->projectId,
                timezone: $settings->timezone,
                defaultCurrency: $settings->defaultCurrency,
                renewalLeadDays: $settings->renewalLeadDays,
                incompleteExpiryHours: $settings->incompleteExpiryHours,
                invoicePrefix: $settings->invoicePrefix,
                defaultTaxRateBps: 0,
                manualReceiptApprovalThresholdMinor: 100_000_000,
                retainInvoiceIdentity: $settings->retainInvoiceIdentity,
                documentBranding: $settings->documentBranding,
            ));
        }
    }

    // ---------------------------------------------------------------- helpers

    private function metrics(): ProjectMetricsView
    {
        $view = app(ProjectMetrics::class)->forProject(self::PROJECT, AccessScope::forProject(self::PROJECT));

        self::assertInstanceOf(ProjectMetricsView::class, $view);

        return $view;
    }

    /**
     * @param array<string, int> $figures
     * @return array<string, int>
     */
    private function sorted(array $figures): array
    {
        ksort($figures);

        return $figures;
    }

    private function plan(string $code, int $price, string $currency, string $unit, int $count, string $project = self::PROJECT): void
    {
        app(CreatePlan::class)->create(new CreatePlanCommand(projectId: $project, code: $code, name: 'Plan '.$code, description: null, actor: 'test:billing'));

        $version = app(CreatePlanVersion::class)->createVersion(new CreatePlanVersionCommand(
            projectId: $project, planCode: $code, priceMinor: $price, currency: $currency,
            intervalUnit: $unit, intervalCount: $count, actor: 'test:billing',
        ));

        app(PublishPlanVersion::class)->publish(new PublishPlanVersionCommand(projectId: $project, planCode: $code, version: $version->version, actor: 'test:billing'));
    }

    private function customer(string $ref, string $project = self::PROJECT): string
    {
        return app(UpsertCustomer::class)->upsert(new UpsertCustomerCommand(
            projectId: $project, externalRef: $ref, displayName: 'Client '.$ref, email: null, phone: null,
            locale: 'fr', metadata: [], expectedVersion: null, actor: 'test:billing', correlationId: null,
        ))->id;
    }

    /**
     * The contract is signed through the Contract, then the lifecycle state
     * the scenario needs is set directly (the transitions have their own tests).
     *
     * @param array<string, string> $columns
     */
    private function subscribe(string $customerRef, string $planCode, string $status, array $columns = [], string $project = self::PROJECT): string
    {
        $this->customer($customerRef, $project);

        $subscription = app(CreateSubscription::class)->create(new CreateSubscriptionCommand(
            projectId: $project, customerRef: $customerRef, planCode: $planCode,
            idempotencyKey: 'key-'.(++$this->sequence), actor: 'test:billing',
        ));

        DB::table('subscriptions')->where('id', $subscription->id)->update(['status' => $status] + $columns);

        return $subscription->id;
    }

    private function subscribeNew(string $customerRef, string $planCode, int $price, string $currency, string $unit, int $count, string $status = 'active', string $project = self::PROJECT): string
    {
        if (! DB::table('plans')->where('project_id', $project)->where('code', $planCode)->exists()) {
            $this->plan($planCode, $price, $currency, $unit, $count, $project);
        }

        return $this->subscribe($customerRef, $planCode, $status, [], $project);
    }

    private function invoice(string $customerRef, int $amount, ?string $dueAt = null, string $currency = 'XAF', string $project = self::PROJECT): string
    {
        $draft = app(CreateInvoice::class)->create(new CreateInvoiceCommand(
            projectId: $project, customerRef: $customerRef,
            lines: [new InvoiceLineInput(description: 'Prestation', quantity: 1, unitAmountMinor: $amount)],
            dueAt: $dueAt, currency: $currency, taxRateBps: 0, actor: 'test:billing',
        ));

        app(IssueInvoice::class)->issue(new IssueInvoiceCommand(projectId: $project, invoiceId: $draft->id, actor: 'test:billing'));

        return $draft->id;
    }

    private function receipt(string $invoiceId, int $amount, string $currency = 'XAF', string $project = self::PROJECT): void
    {
        $receipt = app(RecordManualReceipt::class)->record(new RecordManualReceiptCommand(
            projectId: $project, invoiceId: $invoiceId, method: 'bank_transfer', reference: 'VIR-'.(++$this->sequence),
            amountMinor: $amount, currency: $currency, receivedAt: '2026-10-27T08:00:00Z',
            recordedBy: self::OPERATOR, actor: 'operator:'.self::OPERATOR,
        ));

        self::assertSame('approved', $receipt->status);
    }

    private function counter(string $customerRef, string $meter, int $used, ?int $limit, ?string $windowEnd, ?string $windowStart = null, string $project = self::PROJECT): void
    {
        $customerId = $this->customer($customerRef, $project);

        DB::table('usage_counters')->insert([
            'id' => (string) Str::uuid(),
            'project_id' => $project,
            'owner' => $customerId,
            'customer_id' => $customerId,
            'meter' => $meter,
            'window_start' => $windowStart ?? '2026-10-01T00:00:00Z',
            'window_end' => $windowEnd,
            'limit_value' => $limit,
            'used' => $used,
        ]);
    }
}
