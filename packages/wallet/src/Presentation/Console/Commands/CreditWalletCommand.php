<?php

declare(strict_types=1);

namespace Core\Wallet\Presentation\Console\Commands;

use Core\Wallet\Contracts\CreditWallet;
use Core\Wallet\Contracts\CreditWalletCommand as Command;
use Illuminate\Console\Command as ConsoleCommand;
use Illuminate\Support\Str;

/**
 * An ops/testing tool, not a public API (ADR-0033 §1) — a project's own
 * credentials never grant it the right to mint value into its own wallets.
 */
final class CreditWalletCommand extends ConsoleCommand
{
    protected $signature = 'wallet:credit
        {project : The Project id}
        {party : The Party id}
        {amount : Amount in minor units}
        {currency : e.g. XAF}
        {--ref= : An external reference}';

    protected $description = 'Credit a wallet, creating it lazily if this is its first credit';

    public function handle(CreditWallet $creditWallet): int
    {
        $ref = $this->option('ref');

        $balance = $creditWallet->credit(new Command(
            projectId: $this->argument('project'),
            partyId: $this->argument('party'),
            amountMinor: (int) $this->argument('amount'),
            currency: $this->argument('currency'),
            externalRef: is_string($ref) ? $ref : null,
            idempotencyKey: (string) Str::uuid(),
        ));

        $this->info("Wallet {$balance->walletId}: available {$balance->availableMinor} {$balance->currency}");

        return self::SUCCESS;
    }
}
