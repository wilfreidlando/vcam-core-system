<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use Core\Billing\Contracts\CreateInvoice;
use Core\Billing\Contracts\CreateInvoiceCommand;
use Core\Billing\Contracts\InvoiceLineInput;
use Core\Billing\Contracts\IssueInvoice;
use Core\Billing\Contracts\IssueInvoiceCommand;
use Core\Billing\Contracts\MarkInvoiceUncollectible;
use Core\Billing\Contracts\MarkInvoiceUncollectibleCommand;
use Core\Billing\Contracts\UpsertCustomer;
use Core\Billing\Contracts\UpsertCustomerCommand;
use Core\Identity\Contracts\CreateOrganization;
use Core\Identity\Contracts\CreateOrganizationCommand;
use Core\Identity\Contracts\CreateProject;
use Core\Identity\Contracts\CreateProjectCommand;
use Core\Identity\Domain\Model\Operator;
use Core\Identity\Domain\Port\OperatorRepository;
use Core\Identity\OperatorRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\OperatorFactory;
use Tests\TestCase;

/**
 * ADR-0073 — « une vue créances (factures ouvertes, en retard, irrécouvrables)
 * lue dans le grand livre ».
 *
 * The amounts are the ones the **books** hold, not the sum of the rows
 * listed: a screen that added up the invoices would still be right today,
 * and wrong the day a receipt is corrected.
 */
final class BillingReceivablesConsoleTest extends TestCase
{
    use RefreshDatabase;

    /** An invoice due long ago — always late. */
    private const DUE_PAST = '2020-01-01T00:00:00Z';

    /** An invoice due far away — never late. */
    private const DUE_FUTURE = '2099-01-01T00:00:00Z';

    #[Test]
    public function the_screen_lists_the_open_invoices_the_late_ones_and_the_write_offs(): void
    {
        $projectId = $this->project();

        $this->issuedInvoice($projectId, 111_000, self::DUE_PAST);
        $this->issuedInvoice($projectId, 222_000, self::DUE_FUTURE);
        $written = $this->issuedInvoice($projectId, 444_000, self::DUE_PAST);

        app(MarkInvoiceUncollectible::class)->markUncollectible(new MarkInvoiceUncollectibleCommand(
            projectId: $projectId,
            invoiceId: $written,
            reason: 'Client injoignable',
            actor: 'operator:test',
            correlationId: null,
        ));

        $this->loginAndEnrollMfa(OperatorRole::PlatformAdmin);

        $page = $this->get("/admin/projects/{$projectId}/receivables")->assertOk();

        $page->assertSee('Factures ouvertes')
            ->assertSee('Créances irrécouvrables')
            // The ledger: what is owed is the two open invoices — the
            // write-off left the books.
            ->assertSee('333 000 XAF')
            // What is late: the invoice whose due date has passed.
            ->assertSee('111 000 XAF')
            // What was given up: the bad debt the books carry.
            ->assertSee('444 000 XAF')
            // The open invoice that is not late is listed, but it is not
            // counted as late.
            ->assertSee('222 000 XAF');
    }

    #[Test]
    public function a_write_off_shows_the_date_of_the_act(): void
    {
        $projectId = $this->project();
        $written = $this->issuedInvoice($projectId, 444_000, self::DUE_PAST);

        app(MarkInvoiceUncollectible::class)->markUncollectible(new MarkInvoiceUncollectibleCommand(
            projectId: $projectId,
            invoiceId: $written,
            reason: 'Client injoignable',
            actor: 'operator:test',
            correlationId: null,
        ));

        $this->loginAndEnrollMfa(OperatorRole::PlatformAdmin);

        $page = $this->get("/admin/projects/{$projectId}/receivables")->assertOk();

        // ADR-0094 §12 — the act has a date, and it is the act's own.
        $page->assertSee(now()->format('Y-m-d'));
    }

    private function project(): string
    {
        $organization = app(CreateOrganization::class)->create(
            new CreateOrganizationCommand('Org créances'),
            actor: 'service:test',
            correlationId: null,
        );

        $projectId = app(CreateProject::class)->create(
            new CreateProjectCommand($organization->id, 'Mon Auto-École'),
            actor: 'service:test',
            correlationId: null,
        )->project->id;

        app(UpsertCustomer::class)->upsert(new UpsertCustomerCommand(
            projectId: $projectId, externalRef: 'AE0001', displayName: 'Client',
            email: null, phone: null, locale: 'fr', metadata: [], expectedVersion: null,
            actor: 'test', correlationId: null,
        ));

        return $projectId;
    }

    private function issuedInvoice(string $projectId, int $amount, string $dueAt): string
    {
        $draft = app(CreateInvoice::class)->create(new CreateInvoiceCommand(
            projectId: $projectId, customerRef: 'AE0001', dueAt: $dueAt, currency: 'XAF',
            lines: [new InvoiceLineInput(description: 'Prestation', quantity: 1, unitAmountMinor: $amount)],
            actor: 'test', correlationId: null,
        ));

        app(IssueInvoice::class)->issue(new IssueInvoiceCommand(
            projectId: $projectId, invoiceId: $draft->id, actor: 'test', correlationId: null,
        ));

        return $draft->id;
    }

    private function loginAndEnrollMfa(OperatorRole $role): void
    {
        $created = OperatorFactory::create(uniqid('op-', true).'@example.com', $role);
        $operator = app(OperatorRepository::class)->find($created);
        self::assertInstanceOf(Operator::class, $operator);

        $this->post('/admin/login', ['email' => $operator->email, 'password' => OperatorFactory::PASSWORD])
            ->assertRedirect(route('admin.mfa.enroll.show'));

        // @phpstan-ignore-next-line — TestResponse::getContent() is a dynamic proxy to the underlying Symfony Response.
        $content = $this->get('/admin/mfa/enroll')->getContent();
        $html = is_string($content) ? $content : '';
        if (preg_match('/name="secret"\s+value="([A-Z2-7]+)"/', $html, $secret) !== 1) {
            preg_match('/<code[^>]*>([A-Z2-7]{16,32})<\/code>/', $html, $secret);
        }
        preg_match('/name="challenge_token"\s+value="([^"]+)"/', $html, $challenge);

        $this->post('/admin/mfa/enroll', [
            'challenge_token' => $challenge[1] ?? '',
            'code' => (new Google2FA)->getCurrentOtp($secret[1] ?? ''),
        ])->assertRedirect(route('admin.dashboard'));
    }
}
