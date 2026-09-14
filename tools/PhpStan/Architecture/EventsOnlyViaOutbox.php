<?php

declare(strict_types=1);

namespace Core\Tooling\PhpStan\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

/**
 * Fitness function 8 — no business event escapes outside the transactional outbox.
 *
 * The failure this prevents is specific and expensive: a payment is recorded in
 * the ledger, `payment.completed` is dispatched, and then the transaction rolls
 * back. The consumer has been told about money that does not exist. Or the
 * reverse: the transaction commits and the dispatch is lost, so the consuming
 * product never ships the order.
 *
 * The rule: a state change and the announcement of that state change are written
 * in the SAME database transaction — the event goes into `event_outbox`, and a
 * relay publishes it afterwards. Domain and Application layers therefore must
 * not know the framework dispatcher at all.
 *
 * Global function calls (`event()`, `dispatch()`) are invisible to PHPat and are
 * covered by NoDirectEventDispatchRule instead.
 */
final class EventsOnlyViaOutbox
{
    private const INNER_LAYERS = '/^Core\\\\[A-Za-z]+\\\\(Domain|Application)(\\\\|$)/';

    public function test_business_logic_must_not_dispatch_events_directly(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::INNER_LAYERS, true))
            ->shouldNotDependOn()
            ->classes(
                Selector::classname('Illuminate\Contracts\Events\Dispatcher'),
                Selector::classname('Illuminate\Events\Dispatcher'),
                Selector::classname('Illuminate\Support\Facades\Event'),
                Selector::classname('Illuminate\Foundation\Events\Dispatchable'),
                Selector::classname('Illuminate\Contracts\Bus\Dispatcher'),
                Selector::classname('Illuminate\Support\Facades\Bus'),
                Selector::classname('Illuminate\Broadcasting\BroadcastEvent'),
            )
            ->because(
                'Events are appended to the outbox inside the same transaction as the state '.
                'change, then relayed. Dispatching directly makes state and events divergeable.'
            );
    }
}
