<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

/**
 * ADR-0073 §1 — a manual invoice, as the project declares it.
 *
 * The customer is named by the reference the project knows it by (the
 * correlation key of ADR-0070 §1) — the Core never asks for an id it
 * could get wrong.
 *
 * `currency` and `taxRateBps` are optional: `null` means the setting of
 * the project (`billing_settings.default_currency` and
 * `default_tax_rate_bps`, ADR-0094 §4). The tax of a line is rounded
 * half-up **on that line** (ADR-0094 §1).
 *
 * `dueAt` is optional: an invoice without a due date is payable as soon
 * as it is received.
 */
final readonly class CreateInvoiceCommand
{
    /**
     * @param list<InvoiceLineInput> $lines at least one line
     * @param string|null $dueAt the ISO-8601 instant the invoice falls due
     * @param string|null $currency the ISO-4217 code of the amounts
     * @param int|null $taxRateBps the default rate of the lines that name none
     * @param string $actor "operator:<uuid>", "project:<uuid>" or "service:<name>" — who asked for the write
     */
    public function __construct(
        public string $projectId,
        public string $customerRef,
        public array $lines,
        public ?string $dueAt = null,
        public ?string $currency = null,
        public ?int $taxRateBps = null,
        public string $actor = 'project:unknown',
        public ?string $correlationId = null,
    ) {}
}
