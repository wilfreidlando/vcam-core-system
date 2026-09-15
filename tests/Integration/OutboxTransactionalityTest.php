<?php

declare(strict_types=1);

namespace Tests\Integration;

use Core\Wallet\Contracts\CreditWallet;
use Core\Wallet\Contracts\CreditWalletCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Fitness function 8c — the outbox really is transactional.
 *
 * The static rules (PHPat, NoDirectEventDispatchRule) prove nobody *calls*
 * the dispatcher directly. They cannot prove the outbox row and the state
 * change share a transaction — only running code can.
 *
 * ADR-0032 pulled the outbox's write side (table + append-only port)
 * forward, parallel to Lot 2 — this test uses `CreditWalletUseCase` as its
 * concrete state change, the first real caller of `EventOutbox::append()`.
 * The relay/dispatch side (reading pending events, delivering webhooks)
 * stays Lot 4 and is out of scope here.
 *
 * Owner: the Platform developer.
 *
 * @see docs/architecture/garde-fous-inactifs.md (G8)
 * @see docs/adr/0032-outbox-ecriture-en-parallele-du-lot-2.md
 */
#[Group('integration')]
final class OutboxTransactionalityTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT_ID = '01a0a1cc-0000-7000-8000-000000000020';

    #[Test]
    public function a_state_change_writes_its_event_to_the_outbox(): void
    {
        $view = app(CreditWallet::class)->credit(new CreditWalletCommand(
            self::PROJECT_ID,
            (string) Str::uuid(),
            1_000,
            'XAF',
            null,
            (string) Str::uuid(),
        ));

        $rows = DB::table('event_outbox')->where('project_id', self::PROJECT_ID)->get();
        self::assertCount(1, $rows);

        $row = $rows->first();
        self::assertNotNull($row);
        self::assertSame('wallet.credited', $row->type);

        self::assertIsString($row->payload);
        $payload = json_decode($row->payload, true);
        self::assertIsArray($payload);
        self::assertSame($view->walletId, $payload['wallet_id']);
    }

    #[Test]
    public function a_rolled_back_transaction_leaves_no_outbox_row(): void
    {
        $projectId = self::PROJECT_ID;

        try {
            DB::transaction(function () use ($projectId): void {
                app(CreditWallet::class)->credit(new CreditWalletCommand(
                    $projectId,
                    (string) Str::uuid(),
                    1_000,
                    'XAF',
                    null,
                    (string) Str::uuid(),
                ));

                // Forces the whole outer transaction to roll back — including
                // the credit's own nested unit of work (a SAVEPOINT, since
                // CreditWalletUseCase's UnitOfWork::transactional() call
                // happens while this outer transaction is already open). A
                // caller-level failure after a successful credit must not
                // leave a wallet balance and an outbox event with no
                // corresponding committed state.
                throw new RuntimeException('Simulated failure after the credit, before the caller commits.');
            });
        } catch (RuntimeException) {
            // expected: DB::transaction() rolls back and rethrows.
        }

        self::assertSame(0, DB::table('event_outbox')->where('project_id', $projectId)->count());
        self::assertSame(0, DB::table('wallets')->where('project_id', $projectId)->count());
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('event_outbox')) {
            self::markTestSkipped('The outbox does not exist yet (Lot 4, G8).');
        }
    }
}
