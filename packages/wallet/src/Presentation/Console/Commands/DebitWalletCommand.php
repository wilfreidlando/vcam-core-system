<?php

declare(strict_types=1);

namespace Core\Wallet\Presentation\Console\Commands;

use Core\Wallet\Contracts\DebitWallet;
use Core\Wallet\Contracts\DebitWalletCommand as Command;
use Core\Wallet\Domain\Exception\InsufficientFunds;
use Core\Wallet\Domain\Exception\WalletNotFound;
use Illuminate\Console\Command as ConsoleCommand;
use Illuminate\Support\Str;

final class DebitWalletCommand extends ConsoleCommand
{
    protected $signature = 'wallet:debit
        {wallet : The Wallet id}
        {amount : Amount in minor units}
        {--ref= : An external reference}';

    protected $description = 'Debit an existing wallet';

    public function handle(DebitWallet $debitWallet): int
    {
        try {
            $ref = $this->option('ref');

            $balance = $debitWallet->debit(new Command(
                walletId: $this->argument('wallet'),
                amountMinor: (int) $this->argument('amount'),
                externalRef: is_string($ref) ? $ref : null,
                idempotencyKey: (string) Str::uuid(),
            ));
        } catch (WalletNotFound|InsufficientFunds $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Wallet {$balance->walletId}: available {$balance->availableMinor} {$balance->currency}");

        return self::SUCCESS;
    }
}
