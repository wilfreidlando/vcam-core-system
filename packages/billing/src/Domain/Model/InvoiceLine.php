<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Model;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * ADR-0073 §1 — a line of an invoice.
 *
 * The line carries its own rate and its own rounded tax (ADR-0094 §1):
 * the tax of an invoice is the **sum of the taxes of its lines**, never
 * a rate applied to the subtotal — two lines at two rates give two
 * results, and the Core computes what it is given.
 *
 * The amounts are in minor units (G5); the quantity is an integer, and
 * the amount is the quantity times the unit price — the base checks it.
 */
final readonly class InvoiceLine
{
    public function __construct(
        public string $id,
        public string $invoiceId,
        public string $projectId,
        public InvoiceLineKind $kind,
        public string $description,
        public int $quantity,
        public int $unitAmountMinor,
        public int $amountMinor,
        public int $taxRateBps,
        public int $taxAmountMinor,
        public ?DateTimeImmutable $periodStart,
        public ?DateTimeImmutable $periodEnd,
        public DateTimeImmutable $createdAt,
    ) {}

    /**
     * A line, with its amount and its tax computed from what it is
     * given.
     *
     * @throws InvalidArgumentException when the quantity or the price is
     *                                  not a real one
     */
    public static function of(
        string $id,
        string $invoiceId,
        string $projectId,
        InvoiceLineKind $kind,
        string $description,
        int $quantity,
        int $unitAmountMinor,
        int $taxRateBps,
        DateTimeImmutable $createdAt,
        ?DateTimeImmutable $periodStart = null,
        ?DateTimeImmutable $periodEnd = null,
    ): self {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('An invoice line carries a quantity of at least one.');
        }

        if ($unitAmountMinor < 0) {
            throw new InvalidArgumentException('An invoice line carries a non-negative unit price.');
        }

        if ($taxRateBps < 0 || $taxRateBps > 10000) {
            throw new InvalidArgumentException('An invoice line carries a tax rate between 0 and 10000 basis points.');
        }

        $amountMinor = $quantity * $unitAmountMinor;

        return new self(
            id: $id,
            invoiceId: $invoiceId,
            projectId: $projectId,
            kind: $kind,
            description: $description,
            quantity: $quantity,
            unitAmountMinor: $unitAmountMinor,
            amountMinor: $amountMinor,
            taxRateBps: $taxRateBps,
            taxAmountMinor: self::taxFor($amountMinor, $taxRateBps),
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            createdAt: $createdAt,
        );
    }

    /**
     * ADR-0073 §1 / ADR-0094 §1 — the tax of a line, rounded **half-up**
     * on that line, in integer arithmetic: no float ever touches a
     * money amount (G5).
     *
     * `(x + 5000) / 10000` is the exact half-up rounding of `x / 10000`
     * for a non-negative `x`.
     */
    public static function taxFor(int $amountMinor, int $taxRateBps): int
    {
        if ($amountMinor <= 0 || $taxRateBps <= 0) {
            return 0;
        }

        // The multiplication must not overflow: the amounts are bounded
        // well below the limit, and the check refuses the absurd rather
        // than wrapping around.
        if ($amountMinor > intdiv(PHP_INT_MAX, $taxRateBps)) {
            throw new InvalidArgumentException('An invoice line carries an amount too large to be taxed.');
        }

        return intdiv($amountMinor * $taxRateBps + 5000, 10000);
    }
}
