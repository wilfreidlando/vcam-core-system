<?php

declare(strict_types=1);

namespace Core\Wallet\Presentation\Console\Commands;

use Core\Wallet\Contracts\TransferFunds;
use Core\Wallet\Contracts\TransferFundsCommand;
use Core\Wallet\Contracts\TransferLeg;
use Illuminate\Console\Command as ConsoleCommand;
use Illuminate\Support\Str;

/**
 * Test-infrastructure only, same status as `test:debit-wallet-once`:
 * registered solely in the `testing` environment, the subprocess entry
 * point `TransferConcurrencyTest` spawns to run transfers in truly
 * overlapping database transactions (ADR-0065).
 */
final class TransferFundsForConcurrencyTestingCommand extends ConsoleCommand
{
    protected $signature = 'test:transfer-once {project : The Project id} {from : Source party ref} {to : Destination party ref} {amount : Amount in minor units}';

    protected $description = 'Test-only: perform a single wallet transfer, for concurrency-test subprocess spawning.';

    public function handle(TransferFunds $transfer): int
    {
        /** @var string $projectId */
        $projectId = $this->argument('project');
        /** @var string $from */
        $from = $this->argument('from');
        /** @var string $to */
        $to = $this->argument('to');

        $transfer->transfer(new TransferFundsCommand(
            $projectId,
            'live',
            (string) Str::uuid(),
            'XAF',
            [new TransferLeg($from, $to, (int) $this->argument('amount'))],
        ));

        return self::SUCCESS;
    }
}
