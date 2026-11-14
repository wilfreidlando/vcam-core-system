<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use Core\Billing\Contracts\CreateInvoice;
use Core\Billing\Contracts\CreateInvoiceCommand;
use Core\Billing\Contracts\InvoiceLineInput;
use Core\Billing\Contracts\InvoiceView;
use Core\Billing\Contracts\IssueInvoice;
use Core\Billing\Contracts\IssueInvoiceCommand;
use Core\Billing\Contracts\UpsertCustomer;
use Core\Billing\Contracts\UpsertCustomerCommand;
use Core\Identity\Contracts\CreateOrganization;
use Core\Identity\Contracts\CreateOrganizationCommand;
use Core\Identity\Contracts\CreateProject;
use Core\Identity\Contracts\CreateProjectCommand;
use Core\Identity\Domain\Model\OrganizationMembership;
use Core\Identity\Domain\Model\OrganizationRole;
use Core\Identity\Domain\Port\OperatorTokenIssuer;
use Core\Identity\Domain\Port\OrganizationMembershipRepository;
use Core\Identity\Presentation\Http\Middleware\ResolveOperatorMiddleware;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\OperatorFactory;
use Tests\TestCase;

/**
 * ADR-0125 §1 — the operator prints the invoice of a project.
 *
 * The console shows the **same document** as the API and the portal: it
 * consumes the published contract `GetInvoiceDocument`, and the browser
 * prints it.
 *
 * The read is scoped like every other page of the console: the invoice of
 * another organization is "not found" (404), never "forbidden" (403) —
 * the answer must not confirm that the resource exists (ADR-0081 S-04).
 */
final class BillingInvoiceDocumentConsoleTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_operator_prints_the_invoice_of_their_project(): void
    {
        [$projectId, $token] = $this->tenant();
        $this->withSession([ResolveOperatorMiddleware::SESSION_KEY => $token]);

        $invoice = $this->issueInvoice($projectId);

        $response = $this->get(route('admin.projects.invoices.document', [$projectId, $invoice->id]));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/html; charset=UTF-8');
        $response->assertSee((string) $invoice->number);
        $response->assertSee('Mise en service');
    }

    #[Test]
    public function the_invoice_sheet_offers_the_printable_invoice(): void
    {
        [$projectId, $token] = $this->tenant();
        $this->withSession([ResolveOperatorMiddleware::SESSION_KEY => $token]);

        $invoice = $this->issueInvoice($projectId);

        $this->get(route('admin.projects.invoices.show', [$projectId, $invoice->id]))
            ->assertOk()
            ->assertSee('Facture imprimable')
            ->assertSee(route('admin.projects.invoices.document', [$projectId, $invoice->id]), false);
    }

    #[Test]
    public function the_invoice_of_another_organization_is_not_found(): void
    {
        [$projectId, $token] = $this->tenant();
        $this->withSession([ResolveOperatorMiddleware::SESSION_KEY => $token]);

        [$otherProjectId] = $this->tenant('Autre organisation');
        $otherInvoice = $this->issueInvoice($otherProjectId);

        // The project of another organization: not found, never forbidden.
        $this->get(route('admin.projects.invoices.document', [$otherProjectId, $otherInvoice->id]))
            ->assertNotFound();

        // The invoice of another organization, under our own project: the
        // invoice does not belong to it — not found.
        $this->get(route('admin.projects.invoices.document', [$projectId, $otherInvoice->id]))
            ->assertNotFound();

        // An identifier nobody ever had.
        $this->get(route('admin.projects.invoices.document', [$projectId, '55555555-5555-4555-8555-555555555555']))
            ->assertNotFound();
    }

    /**
     * An organization, its project, and an account that owns it.
     *
     * @return array{0: string, 1: string} the project id and the operator's token
     */
    private function tenant(string $organizationName = 'Organisation console'): array
    {
        $organization = app(CreateOrganization::class)->create(
            new CreateOrganizationCommand($organizationName),
            actor: 'test:console',
            correlationId: null,
        );

        $project = app(CreateProject::class)->create(
            new CreateProjectCommand($organization->id, 'Projet console'),
            actor: 'test:console',
            correlationId: null,
        )->project;

        $operatorId = OperatorFactory::create('console-'.Str::lower(Str::random(8)).'@example.com', null);

        app(OrganizationMembershipRepository::class)->save(new OrganizationMembership(
            id: (string) Str::uuid(),
            organizationId: $organization->id,
            operatorId: $operatorId,
            role: OrganizationRole::Owner,
            createdAt: new DateTimeImmutable,
        ));

        return [$project->id, app(OperatorTokenIssuer::class)->issue($operatorId, 'console-test')];
    }

    /**
     * A customer, and an invoice issued for it.
     */
    private function issueInvoice(string $projectId): InvoiceView
    {
        app(UpsertCustomer::class)->upsert(new UpsertCustomerCommand(
            projectId: $projectId,
            externalRef: 'acme',
            displayName: 'ACME',
            email: 'contact@acme.test',
            phone: '+237690000000',
            locale: 'fr',
            metadata: [],
            expectedVersion: null,
            actor: 'test:console',
            correlationId: null,
        ));

        $draft = app(CreateInvoice::class)->create(new CreateInvoiceCommand(
            projectId: $projectId,
            customerRef: 'acme',
            lines: [new InvoiceLineInput(description: 'Mise en service', quantity: 1, unitAmountMinor: 50_000)],
            actor: 'test:console',
            correlationId: null,
        ));

        return app(IssueInvoice::class)->issue(new IssueInvoiceCommand(
            projectId: $projectId,
            invoiceId: $draft->id,
            actor: 'test:console',
            correlationId: null,
        ));
    }
}
