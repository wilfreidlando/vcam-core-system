<?php

declare(strict_types=1);

namespace Core\Ledger\Domain\Model;

use Core\Ledger\Domain\Exception\UnbalancedTransaction;
use InvalidArgumentException;

/**
 * Double-entry, in one atomic unit (spec technique §6.4). Construction
 * itself enforces the invariant: it is not possible to hold a
 * `LedgerTransaction` instance whose entries do not balance. Immutable and
 * append-only once persisted — a correction is a new, compensating
 * transaction (ADR-0005), never a mutation of this one.
 */
final readonly class LedgerTransaction
{
    /**
     * @param list<LedgerEntry> $entries
     */
    public function __construct(
        public string $id,
        public string $projectId,
        public string $type,
        public ?string $externalRef,
        public string $idempotencyKey,
        public array $entries,
    ) {
        if (count($entries) < 2) {
            throw new InvalidArgumentException('A ledger transaction needs at least two entries.');
        }

        $this->assertSingleCurrency();
        $this->assertBalances();
    }

    private function assertSingleCurrency(): void
    {
        $currencies = array_unique(array_map(
            static fn (LedgerEntry $entry): string => $entry->amount->currency(),
            $this->entries,
        ));

        if (count($currencies) > 1) {
            throw new InvalidArgumentException(
                'A ledger transaction cannot mix currencies ('.implode(', ', $currencies).'). '.
                'A currency conversion is two transactions through an FX account, not one.'
            );
        }
    }

    private function assertBalances(): void
    {
        $imbalance = array_sum(array_map(
            static fn (LedgerEntry $entry): int => $entry->signedAmountMinor(),
            $this->entries,
        ));

        if ($imbalance !== 0) {
            throw UnbalancedTransaction::withImbalance($imbalance);
        }
    }
}
