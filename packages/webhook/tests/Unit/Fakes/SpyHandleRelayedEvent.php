<?php

declare(strict_types=1);

namespace Core\Webhook\Tests\Unit\Fakes;

use Core\Events\Contracts\DomainEvent;
use Core\Webhook\Contracts\HandleRelayedEvent;

/**
 * The delivery-id list is always empty — deliberately: it lets a test
 * prove whether `handle()` was called at all without ever reaching
 * `DeliverWebhookJob::dispatch()`, which needs a booted Laravel container
 * a pure Unit test doesn't provide.
 */
final class SpyHandleRelayedEvent implements HandleRelayedEvent
{
    public int $calls = 0;

    public function handle(DomainEvent $event): array
    {
        $this->calls++;

        return [];
    }
}
