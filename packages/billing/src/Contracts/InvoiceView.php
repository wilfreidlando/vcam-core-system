<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

/**
 * ADR-0073 §1 — the read model of an invoice.
 *
 * Never `Core\Billing\Domain\Model\Invoice`, which stays internal (G3c).
 *
 * `$status` is the canonical `InvoiceStatus` enum's `->value` ('draft',
 * 'open', 'paid', 'void', 'uncollectible'), never the enum itself (G3c).
 *
 * The money is an `int` of minor units plus its currency (G5), and the
 * customer snapshot is **never** returned in clear: the personal values
 * it holds are ciphertexts, and the console masks them (ADR-0083 §3,
 * ADR-0094 §2).
 */
final readonly class InvoiceView
{
    /**
     * @param list<InvoiceLineView> $lines
     * @param array<string, mixed> $customerSnapshot the customer as it was at the issuance — the personal values are kept in clear
     */
    public function __construct(
        public string $id,
        public string $projectId,
        public string $customerId,
        public ?string $customerRef,
        public ?string $subscriptionId,
        public ?string $periodId,
        public ?string $number,
        public string $status,
        public string $currency,
        public int $subtotalMinor,
        public int $taxMinor,
        public int $totalMinor,
        public int $amountPaidMinor,
        public array $customerSnapshot,
        public array $lines,
        public ?string $dueAt,
        public ?string $issuedAt,
        public ?string $paidAt,
        public ?string $voidedAt,
        public ?string $voidReason,
        public ?string $lostAt,
        public int $version,
        public string $createdAt,
        public string $updatedAt,
    ) {}
}
