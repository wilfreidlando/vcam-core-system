<?php

declare(strict_types=1);

namespace Core\Support\Observability;

final class InMemoryExchangeJournal implements ExchangeJournal
{
    /** Hard cap — a retry storm must not grow a worker's memory unbounded. */
    private const MAX_BUFFERED = 50;

    /** @var list<IntegrationExchange> */
    private array $exchanges = [];

    public function record(IntegrationExchange $exchange): void
    {
        $this->exchanges[] = $exchange;

        if (count($this->exchanges) > self::MAX_BUFFERED) {
            $this->exchanges = array_slice($this->exchanges, -self::MAX_BUFFERED);
        }
    }

    public function drain(): array
    {
        $drained = $this->exchanges;
        $this->exchanges = [];

        return $drained;
    }
}
