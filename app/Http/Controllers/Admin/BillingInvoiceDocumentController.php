<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\ResolvesScopedProject;
use Core\Billing\Contracts\GetInvoiceDocument;
use Core\Billing\Contracts\GetInvoiceDocumentCommand;
use Core\Identity\Contracts\GetProject;
use Core\Identity\Contracts\OperatorContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * The printable invoice of a project (ADR-0073 §5, ADR-0125 §1).
 *
 * The operator gets the **same document** as the API and as the portal:
 * the console consumes the published contract `GetInvoiceDocument`, and
 * the browser prints it. The PDF stays out of scope (ADR-0094 §14).
 *
 * The read is scoped like every other page of the console: a project of
 * another organization is "not found" (404), never "forbidden" (403) —
 * the answer must not confirm that the resource exists (ADR-0081 S-04).
 */
final class BillingInvoiceDocumentController
{
    use ResolvesScopedProject;

    public function __construct(
        private readonly GetProject $getProject,
        private readonly GetInvoiceDocument $documents,
    ) {}

    public function show(OperatorContext $operator, string $projectId, string $invoiceId): Response
    {
        $scope = $this->permittedScope($operator, 'invoices:read');
        $this->resolveScopedProject($this->getProject, $operator, $projectId, 'invoices:read');

        $document = $this->documents->document(
            new GetInvoiceDocumentCommand($projectId, $invoiceId),
            // ADR-0069 §2 — the invoice is read in the operator's scope:
            // the invoice of another project is not found.
            $scope,
        );

        if ($document === null) {
            abort(404, 'Facture introuvable.');
        }

        return new Response(
            $document,
            200,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        );
    }
}
