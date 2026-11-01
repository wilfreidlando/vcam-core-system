<?php

declare(strict_types=1);

namespace Tests\Architecture\Idor;

use Core\Billing\Contracts\InvoicePaymentView;
use Core\Billing\Contracts\PayInvoice;
use Core\Billing\Contracts\PayInvoiceCommand;
use Core\Billing\Contracts\PayInvoiceResult;

/**
 * The payment port, watched.
 *
 * It records the commands it receives, so the generated test can prove
 * that the portal did not even **try** to collect a neighbour's invoice —
 * a route that answers "not found" after having started a payment would
 * still be an IDOR.
 */
final class PortalPaymentSpy implements PayInvoice
{
    /** @var list<PayInvoiceCommand> */
    private array $commands = [];

    public function pay(PayInvoiceCommand $command): PayInvoiceResult
    {
        $this->commands[] = $command;
        $sequence = count($this->commands);

        return new PayInvoiceResult(
            new InvoicePaymentView(
                'payment-'.$sequence,
                $command->invoiceId,
                $command->projectId,
                'ref-'.$sequence,
                'pending',
                50_000,
                'XAF',
            ),
            requiresAction: true,
            actionHint: 'Validez sur votre téléphone.',
        );
    }

    /**
     * @return list<PayInvoiceCommand>
     */
    public function commands(): array
    {
        return $this->commands;
    }
}
