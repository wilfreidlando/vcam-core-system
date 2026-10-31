<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use Core\Billing\Application\UseCase\AllocateInvoicePaymentUseCase;
use Core\Billing\Contracts\CreateInvoice;
use Core\Billing\Contracts\CreateInvoiceCommand;
use Core\Billing\Contracts\InvoiceLineInput;
use Core\Billing\Contracts\IssueInvoice;
use Core\Billing\Contracts\IssueInvoiceCommand;
use Core\Billing\Contracts\RecordManualReceipt;
use Core\Billing\Contracts\RecordManualReceiptCommand;
use Core\Billing\Contracts\UpsertCustomer;
use Core\Billing\Contracts\UpsertCustomerCommand;
use Core\Billing\Domain\Model\InvoicePayment;
use Core\Billing\Domain\Model\InvoicePaymentStatus;
use Core\Billing\Domain\Port\InvoicePaymentRepository;
use Core\Identity\Contracts\CreateOrganization;
use Core\Identity\Contracts\CreateOrganizationCommand;
use Core\Identity\Contracts\CreateProject;
use Core\Identity\Contracts\CreateProjectCommand;
use Core\Identity\Contracts\ResolveAccessScope;
use Core\Identity\Domain\Model\Operator;
use Core\Identity\Domain\Port\OperatorRepository;
use Core\Identity\OperatorRole;
use Core\Ledger\Contracts\AccountResolver;
use Core\Ledger\Contracts\Direction;
use Core\Ledger\Contracts\LedgerEntryInput;
use Core\Ledger\Contracts\LedgerPoster;
use Core\Ledger\Contracts\PostLedgerTransactionCommand;
use Core\Ledger\Contracts\ResolveOrCreateAccountCommand;
use Core\Support\Access\AccessScope;
use Core\Support\Clock;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\OperatorFactory;
use Tests\TestCase;

/**
 * ADR-0073 §2, §4 — the sheet of a client shows what it actually paid:
 * the collection that settled online and the receipt recorded by hand, in
 * one chronology.
 *
 * The three rules the console must not break:
 *
 *  - the settlements of a customer of another project never appear on its
 *    sheet, even for an account that may see that project;
 *  - an account that may not read the invoices sees the sheet without the
 *    settlements — the section is not an error, it is absent;
 *  - the list is the newest first, and every row says which invoice it
 *    was imputed on.
 */
final class CustomerSettlementsConsoleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The instant the test drives the collection at (ADR-0087 — the time
     * is injected, the test never waits for it).
     */
    private DateTimeImmutable $now;

    #[Test]
    public function the_sheet_shows_the_online_payment_and_the_manual_receipt_newest_first(): void
    {
        $project = $this->tenant('A');
        $this->customer($project, 'CLIENT-1');

        // The invoice the customer paid online: it is closed by the
        // collection.
        $paid = $this->invoice($project, 'CLIENT-1', 50_000);
        $paymentId = $this->onlinePayment($project, $paid, 50_000);

        // The invoice a transfer was received on: the receipt waits for
        // its validation.
        $open = $this->invoice($project, 'CLIENT-1', 40_000);
        $this->manualReceipt($project, $open, 15_000, 'VIR-2026-0042', $this->eightDaysAgo());

        $this->loginAndEnrollMfa(OperatorRole::PlatformAdmin);

        $response = $this->get("/admin/projects/{$project['projectId']}/customers/CLIENT-1")->assertOk();

        // Both settlements are on the sheet, with their amount and their
        // reference.
        $response->assertSee('15 000');
        $response->assertSee('50 000');
        $response->assertSee('VIR-2026-0042');
        $response->assertSee($paymentId);

        // The newest first: the payment settled on the 28th, the receipt
        // was received on the 20th.
        $response->assertSeeInOrder([$paymentId, 'VIR-2026-0042']);

        // The settlement names the invoice it was imputed on: the number
        // of the invoice appears twice — once in the invoices of the
        // client, once in the settlement.
        self::assertSame(2, substr_count($this->html($response), $this->numberOf($open)));
    }

    #[Test]
    public function the_sheet_of_a_client_never_shows_the_settlements_of_another_project(): void
    {
        $a = $this->tenant('A');
        $b = $this->tenant('B');

        // The same reference in two projects: two different customers.
        $this->customer($a, 'CLIENT-1');
        $this->customer($b, 'CLIENT-1');

        $this->manualReceipt(
            $a,
            $this->invoice($a, 'CLIENT-1', 40_000),
            15_000,
            'VIR-PROJET-A',
            $this->eightDaysAgo(),
        );

        $this->manualReceipt(
            $b,
            $this->invoice($b, 'CLIENT-1', 80_000),
            70_000,
            'VIR-PROJET-B',
            $this->eightDaysAgo(),
        );

        // The account sees the two projects: what it may see is not what
        // the sheet shows.
        $this->loginAndEnrollMfa(OperatorRole::PlatformAdmin);

        $response = $this->get("/admin/projects/{$a['projectId']}/customers/CLIENT-1")->assertOk();

        $response->assertSee('VIR-PROJET-A');
        $response->assertSee('15 000');
        $response->assertDontSee('VIR-PROJET-B');
        $response->assertDontSee('70 000');
    }

    #[Test]
    public function an_account_that_may_not_read_the_invoices_sees_the_sheet_without_the_settlements(): void
    {
        $project = $this->tenant('A');
        $this->customer($project, 'CLIENT-1');
        $this->manualReceipt(
            $project,
            $this->invoice($project, 'CLIENT-1', 40_000),
            15_000,
            'VIR-2026-0042',
            $this->eightDaysAgo(),
        );

        // The account holds `customers:read` — it opens the sheet of the
        // client — but not `invoices:read`: the money of the invoices is
        // not its business, and the sheet says nothing about it.
        $this->loginAsCustomerReader($project);

        $response = $this->get("/admin/projects/{$project['projectId']}/customers/CLIENT-1")->assertOk();

        $response->assertDontSee('Règlements');
        $response->assertDontSee('VIR-2026-0042');
        $response->assertDontSee('15 000');
    }

    #[Test]
    public function a_client_without_any_settlement_is_told_so(): void
    {
        $project = $this->tenant('A');
        $this->customer($project, 'CLIENT-1');
        $this->invoice($project, 'CLIENT-1', 40_000);

        $this->loginAndEnrollMfa(OperatorRole::PlatformAdmin);

        $this->get("/admin/projects/{$project['projectId']}/customers/CLIENT-1")
            ->assertOk()
            ->assertSee('aucun règlement');
    }

    protected function setUp(): void
    {
        parent::setUp();

        // The instant is the real one: the challenge of the login is
        // born at this instant and must not be expired when the sheet is
        // opened.
        $this->now = new DateTimeImmutable('now');

        $clock = new class($this->now) implements Clock
        {
            public function __construct(private readonly DateTimeImmutable $moment) {}

            public function now(): DateTimeImmutable
            {
                return $this->moment;
            }
        };

        $this->app->bind(Clock::class, static fn (): Clock => $clock);
    }

    /**
     * The instant a receipt was received — eight days ago, so it is
     * older than the collection that settled today.
     */
    private function eightDaysAgo(): string
    {
        return $this->now->modify('-8 days')->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * @return array{organizationId: string, projectId: string}
     */
    private function tenant(string $label): array
    {
        $organization = app(CreateOrganization::class)->create(
            new CreateOrganizationCommand('Organisation '.$label),
            actor: 'test:billing',
            correlationId: null,
        );

        $project = app(CreateProject::class)->create(
            new CreateProjectCommand($organization->id, 'Projet '.$label),
            actor: 'test:billing',
            correlationId: null,
        )->project;

        return ['organizationId' => $organization->id, 'projectId' => $project->id];
    }

    /**
     * @param array{organizationId: string, projectId: string} $project
     */
    private function customer(array $project, string $ref): void
    {
        app(UpsertCustomer::class)->upsert(new UpsertCustomerCommand(
            projectId: $project['projectId'],
            externalRef: $ref,
            displayName: 'Client '.$ref,
            email: null,
            phone: null,
            locale: 'fr',
            metadata: [],
            expectedVersion: null,
            actor: 'test:billing',
            correlationId: null,
        ));
    }

    /**
     * An issued invoice of the customer — the document a settlement is
     * imputed on.
     */
    /**
     * @param array{organizationId: string, projectId: string} $project
     */
    private function invoice(array $project, string $ref, int $amountMinor): string
    {
        $draft = app(CreateInvoice::class)->create(new CreateInvoiceCommand(
            projectId: $project['projectId'],
            customerRef: $ref,
            lines: [new InvoiceLineInput(description: 'Prestation', quantity: 1, unitAmountMinor: $amountMinor)],
            actor: 'test:billing',
            correlationId: null,
        ));

        return app(IssueInvoice::class)->issue(new IssueInvoiceCommand(
            projectId: $project['projectId'],
            invoiceId: $draft->id,
            actor: 'test:billing',
            correlationId: null,
        ))->id;
    }

    /**
     * The collection that settled online: the `payment` module books it,
     * then `billing` imputes it on the invoice (ADR-0073 §2, §3).
     *
     * @param array{organizationId: string, projectId: string} $project
     * @return string the id of the payment — the reference the settlement
     *                carries on the sheet
     */
    private function onlinePayment(array $project, string $invoiceId, int $amountMinor): string
    {
        $paymentId = (string) Str::uuid();

        $this->bookTheCollection($project['projectId'], $paymentId, $amountMinor);

        app(InvoicePaymentRepository::class)->save(new InvoicePayment(
            id: (string) Str::uuid(),
            invoiceId: $invoiceId,
            projectId: $project['projectId'],
            paymentId: $paymentId,
            amountMinor: $amountMinor,
            currency: 'XAF',
            status: InvoicePaymentStatus::Pending,
            createdAt: $this->now,
            updatedAt: $this->now,
        ));

        $allocated = app(AllocateInvoicePaymentUseCase::class)->allocate($paymentId, $amountMinor, 'XAF');

        self::assertNotNull($allocated, 'The collection was not imputed on the invoice.');
        self::assertSame('succeeded', $allocated->status);

        return $paymentId;
    }

    /**
     * The receipt recorded by hand: the money arrived outside the Core,
     * and the operator says when and under which reference.
     */
    /**
     * @param array{organizationId: string, projectId: string} $project
     */
    private function manualReceipt(
        array $project,
        string $invoiceId,
        int $amountMinor,
        string $reference,
        string $receivedAt,
    ): void {
        app(RecordManualReceipt::class)->record(new RecordManualReceiptCommand(
            projectId: $project['projectId'],
            invoiceId: $invoiceId,
            method: 'bank_transfer',
            reference: $reference,
            amountMinor: $amountMinor,
            currency: 'XAF',
            receivedAt: $receivedAt,
            recordedBy: '00000000-0000-4000-8000-000000000000',
            actor: 'test:billing',
            correlationId: null,
        ));
    }

    /**
     * What the `payment` module books when the payment settles
     * (ADR-0076 §3).
     */
    private function bookTheCollection(string $projectId, string $paymentId, int $amountMinor): void
    {
        $accounts = app(AccountResolver::class);

        $clearing = $accounts->resolveOrCreate(new ResolveOrCreateAccountCommand(
            $projectId,
            'clearing',
            'my-coolpay',
            'XAF',
        ));

        $collections = $accounts->resolveOrCreate(new ResolveOrCreateAccountCommand(
            $projectId,
            'collections',
            '',
            'XAF',
        ));

        app(LedgerPoster::class)->post(new PostLedgerTransactionCommand(
            $projectId,
            'payment_settlement',
            $paymentId,
            $paymentId,
            [
                new LedgerEntryInput($clearing->id, Direction::Debit, $amountMinor, 'XAF'),
                new LedgerEntryInput($collections->id, Direction::Credit, $amountMinor, 'XAF'),
            ],
        ));
    }

    /**
     * The number of an invoice — the sheet names the invoice of every
     * settlement.
     */
    private function numberOf(string $invoiceId): string
    {
        $number = DB::table('invoices')->where('id', $invoiceId)->value('number');

        self::assertIsString($number);

        return $number;
    }

    /**
     * An account of the organization that holds `customers:read` — it
     * opens the sheet of the client — but not `invoices:read`.
     *
     * The scope is what the console narrows to a permission: it is
     * resolved here by hand, because no role of the Core holds
     * `customers:read` without `invoices:read`.
     *
     * @param array{organizationId: string, projectId: string} $project
     */
    private function loginAsCustomerReader(array $project): void
    {
        $organizationId = $project['organizationId'];
        $projectId = $project['projectId'];

        $scope = new AccessScope(
            allOrganizations: false,
            organizationIds: [$organizationId],
            permissionsByOrganization: [$organizationId => [
                'projects:read',
                'customers:read',
                'subscriptions:read',
                'usage:read',
            ]],
            allProjects: false,
            projectIds: [$projectId],
            projectIdsByOrganization: [$organizationId => [$projectId]],
        );

        $this->app->bind(ResolveAccessScope::class, static fn (): ResolveAccessScope => new class($scope) implements ResolveAccessScope
        {
            public function __construct(private readonly AccessScope $scope) {}

            public function forOperator(string $operatorId): AccessScope
            {
                return $this->scope;
            }
        });

        $this->loginAndEnrollMfa(null);
    }

    private function loginAndEnrollMfa(?OperatorRole $role): void
    {
        $created = OperatorFactory::create(uniqid('op-', true).'@example.com', $role);
        $operator = app(OperatorRepository::class)->find($created);
        assert($operator instanceof Operator);

        $this->post('/admin/login', ['email' => $operator->email, 'password' => OperatorFactory::PASSWORD])
            ->assertRedirect(route('admin.mfa.enroll.show'));

        $enroll = $this->get('/admin/mfa/enroll');
        $html = $this->html($enroll);
        if (preg_match('/name="secret"\s+value="([A-Z2-7]+)"/', $html, $secret) !== 1) {
            preg_match('/<code[^>]*>([A-Z2-7]{16,32})<\/code>/', $html, $secret);
        }
        preg_match('/name="challenge_token"\s+value="([^"]+)"/', $html, $challenge);

        $this->post('/admin/mfa/enroll', [
            'challenge_token' => $challenge[1] ?? '',
            'code' => (new Google2FA)->getCurrentOtp($secret[1] ?? ''),
        ])->assertRedirect(route('admin.dashboard'));
    }

    /**
     * @param TestResponse<\Illuminate\Http\Response> $response
     */
    private function html(TestResponse $response): string
    {
        // @phpstan-ignore-next-line — TestResponse::getContent() is a dynamic proxy to the underlying Symfony Response.
        $content = $response->getContent();

        return is_string($content) ? $content : '';
    }
}
