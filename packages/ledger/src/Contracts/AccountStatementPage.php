<?php

declare(strict_types=1);

namespace Core\Ledger\Contracts;

/**
 * One page of a statement.
 *
 * The opening balance is what the account held strictly before `from`;
 * the closing balance is the opening plus everything the period moved —
 * the whole period, not only the lines of this page, so a paginated
 * statement always states where the account stands at the end of the
 * period.
 */
final readonly class AccountStatementPage
{
    /**
     * @param list<AccountStatementLine> $lines
     */
    public function __construct(
        public array $lines,
        public ?string $nextCursor,
        public int $openingBalanceMinor,
        public int $closingBalanceMinor,
        public string $currency,
    ) {}
}
