<?php

declare(strict_types=1);

namespace Core\Billing\Presentation\Http\Controllers;

use Core\Billing\Contracts\GetInvoiceDocument;
use Core\Billing\Contracts\GetInvoiceDocumentCommand;
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
 *
 * ADR-0125 §2 — the controller consumes the **contract**, never the
 * adapter: the console and the portal read the very same document.
 */
final class GetInvoiceDocumentController
{
    public function __construct(
        private readonly GetInvoiceDocument $documents,
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

        $document = $this->documents->document(
            new GetInvoiceDocumentCommand($project->projectId, $invoiceId),
            // ADR-0069 §2 — the public API plane's scope is the calling
            // project itself: an invoice of another project is not found.
            AccessScope::forProject($project->projectId),
        );

        if ($document === null) {
            return ProblemDetails::make(
                'invoice-not-found',
                'Invoice not found',
                404,
                sprintf('No invoice carries the id "%s" in this project.', $invoiceId),
            );
        }

        return new Response(
            $document,
            200,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        );
    }
}
