<?php

declare(strict_types=1);

namespace Core\Events\Presentation\Console\Commands;

use Core\Events\Contracts\RelayOutboxEvents;
use Illuminate\Console\Command;

final class RelayOutboxEventsCommand extends Command
{
    protected $signature = 'events:relay {--limit= : Maximum outbox rows to relay in this run}';

    protected $description = 'Republish unpublished event_outbox rows (ADR-0040). Scheduled every minute.';

    public function handle(RelayOutboxEvents $relay): int
    {
        $option = $this->option('limit');
        /** @var int $defaultLimit */
        $defaultLimit = config('events.relay.batch_limit', 100);
        $limit = $option === null ? $defaultLimit : (int) $option;

        $relayed = $relay->relay($limit);

        $this->info(sprintf('Relayed %d outbox row(s).', $relayed));

        return self::SUCCESS;
    }
}
