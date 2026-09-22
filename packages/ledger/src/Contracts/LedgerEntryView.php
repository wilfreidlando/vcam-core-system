<?php

declare(strict_types=1);

namespace Core\Ledger\Contracts;

use DateTimeImmutable;

/**
 * `$direction` is the canonical `Direction` enum's `->value` ('debit' or
 * 'credit'), not the enum itself — same G3c reasoning as `PaymentView::$status`.
 */
final readonly class LedgerEntryView
{
    public function __construct(
        public int $id,
        public string $transactionId,
        public string $transactionType,
        public ?string $externalRef,
        public string $direction,
        /** Minor units — never a float, per G5. */
        public int $amountMinor,
        public string $currency,
        public DateTimeImmutable $createdAt,
    ) {}
}
