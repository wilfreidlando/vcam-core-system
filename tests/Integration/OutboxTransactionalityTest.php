<?php

declare(strict_types=1);

namespace Tests\Integration;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fitness function 8c — the outbox really is transactional.
 *
 * The static rules (PHPat, NoDirectEventDispatchRule) prove nobody *calls* the
 * dispatcher. They cannot prove the outbox row and the state change share a
 * transaction — only running code can.
 *
 * Both directions matter:
 *   · state committed, event lost  → the consumer never learns the payment
 *     succeeded, so the order never ships;
 *   · event published, state rolled back → the consumer is told about money
 *     that does not exist, and reconciliation finds the discrepancy days later.
 *
 * Owner: the Platform developer. Activates with Lot 4.
 *
 * @see docs/architecture/garde-fous-inactifs.md (G8)
 */
#[Group('integration')]
final class OutboxTransactionalityTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_state_change_writes_its_event_to_the_outbox(): void
    {
        self::markTestIncomplete('Implemented in Lot 4 with the first outbox-backed use case.');
    }

    #[Test]
    public function a_rolled_back_transaction_leaves_no_outbox_row(): void
    {
        self::markTestIncomplete(
            'Implemented in Lot 4: force a failure after the state write and assert the '.
            'outbox row rolled back with it.'
        );
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('event_outbox')) {
            self::markTestSkipped('The outbox does not exist yet (Lot 4, G8).');
        }
    }
}
