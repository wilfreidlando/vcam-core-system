<?php

declare(strict_types=1);

namespace Core\Portal\Tests\Integration;

use Core\Billing\Contracts\CreateInvoice;
use Core\Billing\Contracts\CreateInvoiceCommand;
use Core\Billing\Contracts\InvoiceLineInput;
use Core\Billing\Contracts\IssueInvoice;
use Core\Billing\Contracts\IssueInvoiceCommand;
use Core\Billing\Contracts\UpsertCustomer;
use Core\Billing\Contracts\UpsertCustomerCommand;
use Core\Identity\Contracts\CreateOrganization;
use Core\Identity\Contracts\CreateOrganizationCommand;
use Core\Identity\Contracts\CreateProject;
use Core\Identity\Contracts\CreateProjectCommand;
use Core\Identity\Contracts\IssueApiKey;
use Core\Identity\Contracts\IssueApiKeyCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ADR-0125 §1 — the customer gets their own invoice, printable.
 *
 * The document of the portal is the **same** one the API and the console
 * show: the frozen lines of the invoice and the header of the project.
 *
 * The invoice is the one of the session's customer: the invoice of the
 * neighbour — of the **same** project, which is the boundary the portal
 * has to hold — is not found (404, never 403 — ADR-0081 S-04).
 */
final class PortalInvoiceDocumentTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_customer_gets_the_printable_invoice_of_their_own_invoice(): void
    {
        [$token, $projectId] = $this->project();
        $this->customer($projectId, 'AE0001', 'Soleil');
        $this->customer($projectId, 'AE0002', 'Lune');
        $mine = $this->invoice($projectId, 'AE0001', 'Abonnement Soleil');
        $theirs = $this->invoice($projectId, 'AE0002', 'Abonnement Lune');

        $cookie = $this->loginAs($token, 'AE0001');

        $response = $this->withUnencryptedCookie('portal', $cookie)
            ->get('/portal/invoices/'.$mine.'/document');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/html; charset=UTF-8');
        $response->assertSee('Abonnement Soleil');
        $response->assertSee('Soleil');
        $response->assertDontSee('Abonnement Lune');

        // The invoice of the neighbour does not exist for this customer:
        // not "forbidden" — a 403 would confirm that it exists.
        $this->withUnencryptedCookie('portal', $cookie)
            ->get('/portal/invoices/'.$theirs.'/document')
            ->assertNotFound();

        // An identifier nobody ever had answers the same page.
        $this->withUnencryptedCookie('portal', $cookie)
            ->get('/portal/invoices/55555555-5555-4555-8555-555555555555/document')
            ->assertNotFound();
    }

    #[Test]
    public function the_invoice_page_offers_the_printable_invoice(): void
    {
        [$token, $projectId] = $this->project();
        $this->customer($projectId, 'AE0001', 'Soleil');
        $invoice = $this->invoice($projectId, 'AE0001', 'Abonnement Soleil');

        $cookie = $this->loginAs($token, 'AE0001');

        $this->withUnencryptedCookie('portal', $cookie)
            ->get('/portal/invoices/'.$invoice)
            ->assertOk()
            ->assertSee('Télécharger la facture')
            ->assertSee('/portal/invoices/'.$invoice.'/document', false);
    }

    #[Test]
    public function the_document_is_dressed_by_a_same_origin_stylesheet_and_can_be_saved(): void
    {
        [$token, $projectId] = $this->project();
        $this->customer($projectId, 'AE0001', 'Soleil');
        $invoice = $this->invoice($projectId, 'AE0001', 'Abonnement Soleil');

        $cookie = $this->loginAs($token, 'AE0001');

        $response = $this->withUnencryptedCookie('portal', $cookie)
            ->get('/portal/invoices/'.$invoice.'/document');

        $response->assertOk();

        // The portal's content security policy is strict: `style-src
        // 'self'`. An inline `<style>` would be refused, and the customer
        // would read an unformatted invoice — so the document links a
        // same-origin stylesheet instead.
        $response->assertDontSee('<style', false);
        $response->assertSee('/assets/invoice-document.css', false);

        // The link says « Télécharger la facture »: saving the document
        // gives a file that says what it is.
        self::assertStringContainsString('facture-', (string) $response->headers->get('Content-Disposition'));
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @param list<string> $scopes
     * @return array{0: string, 1: string} the API token and the project id
     */
    private function project(array $scopes = ['portal:write']): array
    {
        $organization = app(CreateOrganization::class)->create(new CreateOrganizationCommand('Org'), actor: 'service:test', correlationId: null);
        $project = app(CreateProject::class)->create(new CreateProjectCommand($organization->id, 'Projet'), actor: 'service:test', correlationId: null)->project;
        $issued = app(IssueApiKey::class)->issue(new IssueApiKeyCommand($project->id, $scopes), actor: 'service:test', correlationId: null);

        return [$issued->plaintextToken, $project->id];
    }

    private function customer(string $projectId, string $ref, string $name): void
    {
        app(UpsertCustomer::class)->upsert(new UpsertCustomerCommand(
            projectId: $projectId, externalRef: $ref, displayName: $name, email: null, phone: null,
            locale: 'fr', metadata: [], expectedVersion: null, actor: 'test', correlationId: null,
        ));
    }

    private function invoice(string $projectId, string $ref, string $description): string
    {
        $draft = app(CreateInvoice::class)->create(new CreateInvoiceCommand(
            projectId: $projectId, customerRef: $ref,
            lines: [new InvoiceLineInput(description: $description, quantity: 1, unitAmountMinor: 50_000)],
            actor: 'test', correlationId: null,
        ));

        return app(IssueInvoice::class)->issue(new IssueInvoiceCommand(
            projectId: $projectId, invoiceId: $draft->id, actor: 'test', correlationId: null,
        ))->id;
    }

    /**
     * The cookie of the portal: the one-time link is exchanged for it.
     */
    private function loginAs(string $token, string $ref): string
    {
        $url = $this->postJson('/v1/portal/sessions', ['customer_ref' => $ref], ['Authorization' => 'Bearer '.$token])
            ->assertStatus(201)
            ->json('url');
        self::assertIsString($url);

        $response = $this->get((string) parse_url($url, PHP_URL_PATH));
        $cookie = collect($response->headers->getCookies())->first(fn ($c): bool => $c->getName() === 'portal');

        return $cookie?->getValue() ?? '';
    }
}
