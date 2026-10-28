<?php

declare(strict_types=1);

namespace Core\Billing\Application\UseCase;

use Core\Billing\Application\Support\InvoicePaymentViews;
use Core\Billing\Contracts\InvoicePaymentPage;
use Core\Billing\Contracts\InvoicePaymentView;
use Core\Billing\Contracts\ListInvoicePayments;
use Core\Billing\Contracts\ListInvoicePaymentsCommand;
use Core\Billing\Domain\Model\InvoicePayment;
use Core\Billing\Domain\Port\InvoicePaymentRepository;
use Core\Support\Access\AccessScope;

/**
 * ADR-0073 §2 — the attempts to collect one invoice, page after page.
 *
 * The scope is a **required** parameter (ADR-0069 §2): the listing
 * never crosses the projects the console account may see, and an
 * explicit project outside the scope yields an empty page rather than
 * another tenant's attempts.
 */
final readonly class ListInvoicePaymentsUseCase implements ListInvoicePayments
{
    public function __construct(
        private InvoicePaymentRepository $payments,
    ) {}

    public function list(ListInvoicePaymentsCommand $command, AccessScope $scope): InvoicePaymentPage
    {
        if (! $scope->allowsProject($command->projectId)) {
            return new InvoicePaymentPage([]);
        }

        $page = $this->payments->listByInvoice($command->invoiceId, $command->cursor, $command->limit);

        $items = array_map(
            fn (InvoicePayment $payment): InvoicePaymentView => InvoicePaymentViews::from($payment),
            $page['items'],
        );

        return new InvoicePaymentPage($items, $page['nextCursor']);
    }
}
