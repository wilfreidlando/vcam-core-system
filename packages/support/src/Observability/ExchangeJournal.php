<?php

declare(strict_types=1);

namespace Core\Support\Observability;

/**
 * Request-scoped (one per HTTP request / queued job) buffer of the provider
 * exchanges made so far. `ExternalApiCaller` records into it; a use case
 * drains it right after the provider call it made, to attach the exact
 * exchange to its own timeline (ADR-0059). Holds nothing durable — the
 * module that drains decides what to persist (G3d).
 */
interface ExchangeJournal
{
    public function record(IntegrationExchange $exchange): void;

    /**
     * Returns every exchange recorded since the last drain, oldest first,
     * and empties the buffer.
     *
     * @return list<IntegrationExchange>
     */
    public function drain(): array;
}
