<?php

declare(strict_types=1);

namespace Core\Portal\Presentation\Http\Controllers;

use Core\Billing\Contracts\GetInvoiceDocument;
use Core\Billing\Contracts\GetInvoiceDocumentCommand;
use Core\Portal\Application\Dto\PortalPrincipal;
use Core\Portal\Application\UseCase\PortalSessionService;
use Core\Portal\Application\UseCase\PortalWorkspace;
use Core\Portal\Presentation\Http\Middleware\AuthenticatePortal;
use Core\Support\Access\AccessScope;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The printable invoice of the customer (ADR-0073 §5, ADR-0125 §1).
 *
 * The portal shows the **same document** as the API and as the console: it
 * consumes the published contract `Core\Billing\Contracts\GetInvoiceDocument`,
 * so the three cannot diverge.
 *
 * The invoice is the one of the session's customer — the check is the very
 * one the portal's invoice page does (`PortalWorkspace::invoice()`): the
 * invoice of another customer is **not found** (404, never 403 —
 * ADR-0081 S-04).
 */
final class PortalInvoiceDocumentController
{
    public function __construct(
        private readonly PortalSessionService $sessions,
        private readonly PortalWorkspace $workspace,
        private readonly GetInvoiceDocument $documents,
    ) {}

    public function document(Request $request, string $invoiceId): Response
    {
        $who = $this->who($request);

        // The invoice of the session's customer — or nothing at all.
        $detail = $this->workspace->invoice($who, $invoiceId);

        if ($detail === null) {
            return $this->notFound($who);
        }

        $document = $this->documents->document(
            new GetInvoiceDocumentCommand($who->projectId, $invoiceId),
            // ADR-0069 §2 — the session's project: the invoice of another
            // project is not found.
            AccessScope::forProject($who->projectId),
        );

        if ($document === null) {
            return $this->notFound($who);
        }

        return new Response(
            $document,
            200,
            [
                'Content-Type' => 'text/html; charset=UTF-8',
                // The link says « Télécharger la facture »: the document is
                // **displayed** — the customer reads it and prints it — but
                // it carries a name, so that saving it gives a file that
                // says what it is.
                'Content-Disposition' => 'inline; filename="'.$this->fileName($detail->invoice->number).'"',
            ],
        );
    }

    /**
     * The name the customer saves the document under.
     *
     * The number of the invoice is the only thing that identifies it for
     * the customer; it is written as it is, with the letters and digits
     * only, so the name is always usable as a file name.
     */
    private function fileName(?string $number): string
    {
        $clean = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $number) ?? '';

        return 'facture-'.($clean === '' ? 'brouillon' : $clean).'.html';
    }

    private function who(Request $request): PortalPrincipal
    {
        /** @var PortalPrincipal $principal */
        $principal = $request->attributes->get(AuthenticatePortal::ATTRIBUTE);

        return $principal;
    }

    /**
     * The very page the portal shows for a resource that does not exist:
     * the answer must not tell the neighbour's invoice from an identifier
     * nobody ever had (ADR-0081 S-04).
     */
    private function notFound(PortalPrincipal $who): Response
    {
        return response()->view('portal::error', [
            'principal' => $who,
            'csrf' => $this->sessions->csrfToken($who),
            'title' => 'Introuvable',
            'message' => 'Cette page n\'existe pas ou ne vous est pas accessible.',
        ], 404);
    }
}
