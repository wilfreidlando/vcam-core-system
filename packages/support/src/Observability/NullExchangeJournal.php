<?php

declare(strict_types=1);

namespace Core\Support\Observability;

final class NullExchangeJournal implements ExchangeJournal
{
    public function record(IntegrationExchange $exchange): void {}

    public function drain(): array
    {
        return [];
    }
}
