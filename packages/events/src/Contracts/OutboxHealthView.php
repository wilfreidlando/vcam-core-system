<?php

declare(strict_types=1);

namespace Core\Events\Contracts;

final readonly class OutboxHealthView
{
    public function __construct(
        public int $unpublishedCount,
        /** Seconds since the oldest unpublished row was written; 0 when none. */
        public int $oldestUnpublishedAgeSeconds,
    ) {}
}
