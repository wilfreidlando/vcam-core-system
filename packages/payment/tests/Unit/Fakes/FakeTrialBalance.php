<?php

declare(strict_types=1);

namespace Core\Payment\Tests\Unit\Fakes;

use Core\Ledger\Contracts\AccountBalanceView;
use Core\Ledger\Contracts\TrialBalance;
use DateTimeImmutable;

/**
 * A `TrialBalance` the test feeds by hand: the balances of the accounts
 * the resolution guard reads.
 */
final class FakeTrialBalance implements TrialBalance
{
    /** @var list<AccountBalanceView> */
    public array $balances = [];

    public function forProject(string $projectId, ?DateTimeImmutable $at = null): array
    {
        return $this->balances;
    }

    /**
     * What the exception pool holds — credited, because the pool is
     * credited when money is collected and cannot be allocated.
     */
    public function creditPool(string $currency, int $amountMinor): void
    {
        $this->balances[] = new AccountBalanceView(
            kind: 'payment_exceptions',
            reference: '',
            currency: $currency,
            debitMinor: 0,
            creditMinor: $amountMinor,
        );
    }
}
