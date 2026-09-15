<?php

declare(strict_types=1);

namespace Core\Events\Infrastructure\Persistence\Eloquent;

use Core\Events\Contracts\DomainEvent;
use Core\Events\Contracts\EventOutbox;

final class EloquentEventOutbox implements EventOutbox
{
    public function append(DomainEvent $event): void
    {
        EventOutboxRecord::query()->create([
            'id' => $event->id,
            'project_id' => $event->projectId,
            'type' => $event->type,
            'payload' => $event->payload,
            'correlation_id' => $event->correlationId,
            'published_at' => null,
            'created_at' => $event->occurredAt,
        ]);
    }
}
