<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

/**
 * ADR-0073 §2 — `POST /v1/invoices/{id}/pay`: the collection of an
 * invoice, online.
 *
 * `billing` calls the `payment` module through its published contract,
 * with the intention `invoice` (ADR-0076 §3, ADR-0094 §9): the money
 * lands on `collections`, and `billing` allocates it onto the
 * customer's receivable once the payment settles.
 *
 * The route carries an `Idempotency-Key` (G7): a replayed request never
 * starts a second collection.
 */
interface PayInvoice
{
    public function pay(PayInvoiceCommand $command): PayInvoiceResult;
}
