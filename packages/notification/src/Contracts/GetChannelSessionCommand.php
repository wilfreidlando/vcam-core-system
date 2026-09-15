<?php

declare(strict_types=1);

namespace Core\Notification\Contracts;

/**
 * `sessionRef` is caller-supplied (ADR-0042 §4) — the Core does not
 * generate or persist it, it's forwarded to the resolved provider as-is.
 */
final readonly class GetChannelSessionCommand
{
    public function __construct(
        public string $projectId,
        public NotificationChannel $channel,
        public string $sessionRef,
    ) {}
}
