<?php

declare(strict_types=1);

namespace Core\Billing\Application\UseCase;

use Core\Billing\Application\Port\InvoiceDocumentRenderer;
use Core\Billing\Contracts\GetInvoice;
use Core\Billing\Contracts\GetInvoiceCommand;
use Core\Billing\Contracts\GetInvoiceDocument;
use Core\Billing\Contracts\GetInvoiceDocumentCommand;
use Core\Billing\Domain\Port\BillingSettingsRepository;
use Core\Support\Access\AccessScope;

/**
 * ADR-0073 §5, ADR-0125 §1 — the printable invoice.
 *
 * The use case knows the invoice and the header of the project; the HTML
 * is the **port's** business (ADR-0125 §2 — the Application never builds a
 * document, and the Presentation never reaches the adapter).
 *
 * The invoice is read through the published contract `GetInvoice`, in the
 * caller's scope: an invoice of another project is **not found**, never
 * told apart from one that does not exist (ADR-0069 §2).
 */
final readonly class GetInvoiceDocumentUseCase implements GetInvoiceDocument
{
    public function __construct(
        private GetInvoice $getInvoice,
        private BillingSettingsRepository $settings,
        private InvoiceDocumentRenderer $renderer,
    ) {}

    public function document(GetInvoiceDocumentCommand $command, AccessScope $scope): ?string
    {
        $invoice = $this->getInvoice->get(
            new GetInvoiceCommand($command->projectId, $command->invoiceId),
            $scope,
        );

        if ($invoice === null) {
            return null;
        }

        return $this->renderer->render(
            $invoice,
            $this->settings->find($command->projectId)->documentBranding,
        );
    }
}
