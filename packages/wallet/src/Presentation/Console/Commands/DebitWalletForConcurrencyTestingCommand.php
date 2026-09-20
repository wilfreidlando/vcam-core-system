<?php

declare(strict_types=1);

namespace Core\Wallet\Presentation\Console\Commands;

use Core\Wallet\Contracts\DebitWallet;
use Core\Wallet\Contracts\DebitWalletCommand as Command;
use Illuminate\Console\Command as ConsoleCommand;
use Illuminate\Support\Str;

/**
 * Test-infrastructure only — never a production capability. A single PHP
 * process can't hold two overlapping database transactions at once, so
 * `WalletConcurrencyTest` spawns real OS processes to exercise the row
 * lock in `DebitWalletUseCase::debit()`; this command is the subprocess
 * entrypoint it shells out to. Registered by `WalletServiceProvider` only
 * when `app()->environment('testing')` — not `local`, never production —
 * so it carries none of the risk the removed `wallet:debit` ops command
 * did (security review finding: no caller-side ownership verification for
 * a manual credit/debit path with no real operational need).
 */
final class DebitWalletForConcurrencyTestingCommand extends ConsoleCommand
{
    protected $signature = 'test:debit-wallet-once {project : The Project id} {wallet : The Wallet id} {amount : Amount in minor units}';

    protected $description = 'Test-only: perform a single wallet debit, for concurrency-test subprocess spawning.';

    public function handle(DebitWallet $debitWallet): int
    {
        /** @var string $projectId */
        $projectId = $this->argument('project');
        /** @var string $walletId */
        $walletId = $this->argument('wallet');

        $debitWallet->debit(new Command(
            projectId: $projectId,
            walletId: $walletId,
            amountMinor: (int) $this->argument('amount'),
            externalRef: null,
            idempotencyKey: (string) Str::uuid(),
        ));

        return self::SUCCESS;
    }
}
