<?php

declare(strict_types=1);

namespace Core\Notification\Tests\Unit\Fakes;

use Core\Events\Contracts\DomainEvent;
use Core\Events\Contracts\EventOutbox;

final class InMemoryEventOutbox implements EventOutbox
{
    /** @var list<DomainEvent> */
    public array $events = [];

    public function append(DomainEvent $event): void
    {
        $this->events[] = $event;
    }
}
