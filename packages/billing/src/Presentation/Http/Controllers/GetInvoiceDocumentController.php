<?php

declare(strict_types=1);

namespace Core\Billing\Presentation\Http\Controllers;

use Core\Billing\Contracts\GetInvoice;
use Core\Billing\Contracts\GetInvoiceCommand;
use Core\Billing\Domain\Port\BillingSettingsRepository;
use Core\Billing\Presentation\Documents\InvoiceDocumentRenderer;
use Core\Identity\Contracts\ProjectContext;
use Core\Support\Access\AccessScope;
use Core\Support\Http\ProblemDetails;
use Dedoc\Scramble\Attributes\Response as ScrambleResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * `GET /v1/invoices/{invoiceId}/document` — ADR-0073 §5.
 *
 * The printable invoice: HTML, rendered from the frozen lines. The PDF
 * comes later, with the library that will produce it (ADR-0094 §14) —
 * the Core does not depend on a renderer for a document it already
 * renders.
 */
final class GetInvoiceDocumentController
{
    public function __construct(
        private readonly GetInvoice $getInvoice,
        private readonly BillingSettingsRepository $settings,
        private readonly InvoiceDocumentRenderer $renderer,
    ) {}

    /**
     * Get the printable invoice of the calling Project.
     *
     * Answers the HTML document, ready to print. Requires the
     * `invoices:read` scope.
     */
    #[ScrambleResponse(status: 200, type: 'string', description: 'The printable invoice, as HTML.')]
    public function __invoke(string $invoiceId, ProjectContext $project): Response
    {
        if (! $project->hasScope('invoices:read')) {
            return ProblemDetails::forbidden('The invoices:read scope is required.');
        }

        $view = $this->getInvoice->get(
            new GetInvoiceCommand($project->projectId, $invoiceId),
            // ADR-0069 §2 — the public API plane's scope is the calling
            // project itself: an invoice of another project is not found.
            AccessScope::forProject($project->projectId),
        );

        if ($view === null) {
            return ProblemDetails::make(
                'invoice-not-found',
                'Invoice not found',
                404,
                sprintf('No invoice carries the id "%s" in this project.', $invoiceId),
            );
        }

        $settings = $this->settings->find($project->projectId);

        return new Response(
            $this->renderer->render($view, $settings->documentBranding),
            200,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        );
    }
}
