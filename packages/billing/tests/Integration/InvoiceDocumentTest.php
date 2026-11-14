<?php

declare(strict_types=1);

namespace Core\Billing\Tests\Integration;

use Core\Billing\Contracts\CreateInvoice;
use Core\Billing\Contracts\CreateInvoiceCommand;
use Core\Billing\Contracts\GetInvoiceDocument;
use Core\Billing\Contracts\GetInvoiceDocumentCommand;
use Core\Billing\Contracts\InvoiceLineInput;
use Core\Billing\Contracts\InvoiceView;
use Core\Billing\Contracts\IssueInvoice;
use Core\Billing\Contracts\IssueInvoiceCommand;
use Core\Billing\Contracts\UpsertCustomer;
use Core\Billing\Contracts\UpsertCustomerCommand;
use Core\Billing\Domain\Model\BillingSettings;
use Core\Billing\Domain\Port\BillingSettingsRepository;
use Core\Support\Access\AccessScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ProjectsWithKeys;
use Tests\TestCase;

/**
 * ADR-0125 §1 — the printable invoice, through the published contract.
 *
 * The contract is the **only** door to the document: the API, the console
 * and the portal read it, so the three cannot diverge (ADR-0125 §1).
 *
 * Two rules are pinned here:
 *
 *  - the document carries the **frozen lines** of the invoice and the
 *    header of the project (ADR-0073 §5);
 *  - an invoice of another project is **not found** — never told apart
 *    from one that does not exist (ADR-0069 §2).
 */
final class InvoiceDocumentTest extends TestCase
{
    use ProjectsWithKeys;
    use RefreshDatabase;

    private const OTHER_PROJECT = '44444444-4444-4444-8444-444444444444';

    private string $projectId;

    #[Test]
    public function the_document_carries_the_frozen_lines_and_the_header_of_the_project(): void
    {
        $this->declareCustomer();
        $this->brandTheProject('Ma Société', 'Merci pour votre confiance.');

        $invoice = $this->issueInvoice('Mise en service', 50_000);

        $document = app(GetInvoiceDocument::class)->document(
            new GetInvoiceDocumentCommand($this->projectId, $invoice->id),
            AccessScope::forProject($this->projectId),
        );

        self::assertNotNull($document);

        // The number of the invoice — the document is the one that was
        // issued, not a draft.
        self::assertStringContainsString((string) $invoice->number, $document);

        // The frozen line, and the customer frozen with it (ADR-0123).
        self::assertStringContainsString('Mise en service', $document);
        self::assertStringContainsString('ACME', $document);
        self::assertStringContainsString('acme', $document);

        // The totals.
        self::assertStringContainsString('50000', $document);

        // The header of the project (ADR-0094 §4).
        self::assertStringContainsString('Ma Société', $document);
        self::assertStringContainsString('Merci pour votre confiance.', $document);
    }

    #[Test]
    public function the_api_shows_the_very_same_document(): void
    {
        $this->declareCustomer();

        $invoice = $this->issueInvoice('Mise en service', 50_000);

        $document = app(GetInvoiceDocument::class)->document(
            new GetInvoiceDocumentCommand($this->projectId, $invoice->id),
            AccessScope::forProject($this->projectId),
        );

        self::assertNotNull($document);

        $response = $this->get('/v1/invoices/'.$invoice->id.'/document', $this->keyFor($this->projectId, ['invoices:read']));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/html; charset=UTF-8');

        // Byte for byte the document of the contract: the API, the console
        // and the portal show the same one (ADR-0125 §1).
        self::assertSame($document, (string) $response->baseResponse->getContent());
    }

    #[Test]
    public function an_invoice_of_another_project_is_not_found(): void
    {
        $this->declareCustomer();

        $invoice = $this->issueInvoice('Mise en service', 50_000);

        $documents = app(GetInvoiceDocument::class);

        // The invoice of another project, asked for in another project:
        // not found (ADR-0069 §2).
        self::assertNull($documents->document(
            new GetInvoiceDocumentCommand(self::OTHER_PROJECT, $invoice->id),
            AccessScope::forProject(self::OTHER_PROJECT),
        ));

        // The invoice of another project, asked for in its own project:
        // the project of the command wins — the invoice does not belong
        // to it.
        self::assertNull($documents->document(
            new GetInvoiceDocumentCommand($this->projectId, $invoice->id),
            AccessScope::forProject(self::OTHER_PROJECT),
        ));

        // An identifier nobody ever had.
        self::assertNull($documents->document(
            new GetInvoiceDocumentCommand($this->projectId, '55555555-5555-4555-8555-555555555555'),
            AccessScope::forProject($this->projectId),
        ));
    }

    #[Test]
    public function what_the_project_writes_is_escaped_in_the_document(): void
    {
        $this->declareCustomer();

        $invoice = $this->issueInvoice('<script>alert(1)</script>', 1_000);

        $document = app(GetInvoiceDocument::class)->document(
            new GetInvoiceDocumentCommand($this->projectId, $invoice->id),
            AccessScope::forProject($this->projectId),
        );

        self::assertNotNull($document);
        self::assertStringNotContainsString('<script>', $document);
        self::assertStringContainsString('&lt;script&gt;', $document);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->projectId = $this->makeProject('Facturation')['project_id'];
    }

    private function declareCustomer(): void
    {
        app(UpsertCustomer::class)->upsert(new UpsertCustomerCommand(
            projectId: $this->projectId,
            externalRef: 'acme',
            displayName: 'ACME',
            email: 'contact@acme.test',
            phone: '+237690000000',
            locale: 'fr',
            metadata: [],
            expectedVersion: null,
            actor: 'test:billing',
            correlationId: null,
        ));
    }

    private function issueInvoice(string $description, int $amountMinor): InvoiceView
    {
        $draft = app(CreateInvoice::class)->create(new CreateInvoiceCommand(
            projectId: $this->projectId,
            customerRef: 'acme',
            lines: [new InvoiceLineInput(description: $description, quantity: 1, unitAmountMinor: $amountMinor)],
            actor: 'test:billing',
            correlationId: null,
        ));

        return app(IssueInvoice::class)->issue(new IssueInvoiceCommand(
            projectId: $this->projectId,
            invoiceId: $draft->id,
            actor: 'test:billing',
            correlationId: null,
        ));
    }

    /**
     * The header of the printed documents (ADR-0094 §4).
     */
    private function brandTheProject(string $companyName, string $mentions): void
    {
        $repository = app(BillingSettingsRepository::class);
        $settings = $repository->find($this->projectId);

        $repository->save(new BillingSettings(
            projectId: $settings->projectId,
            timezone: $settings->timezone,
            defaultCurrency: $settings->defaultCurrency,
            renewalLeadDays: $settings->renewalLeadDays,
            incompleteExpiryHours: $settings->incompleteExpiryHours,
            invoicePrefix: $settings->invoicePrefix,
            defaultTaxRateBps: $settings->defaultTaxRateBps,
            manualReceiptApprovalThresholdMinor: $settings->manualReceiptApprovalThresholdMinor,
            retainInvoiceIdentity: $settings->retainInvoiceIdentity,
            documentBranding: ['company_name' => $companyName, 'mentions' => $mentions],
            meterMappings: $settings->meterMappings,
            refundFailedUsage: $settings->refundFailedUsage,
            requireCustomerRef: $settings->requireCustomerRef,
            supplementPacks: $settings->supplementPacks,
        ));
    }
}
