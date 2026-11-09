<?php

declare(strict_types=1);

namespace Core\Notification\Domain\Model;

/**
 * ADR-0118 §4 — one declaration of a project: « cet événement, sur ce
 * canal ».
 *
 * The module stores the strings without knowing what they mean: the events
 * are the commerce's vocabulary, and the channels are the adapters' — the
 * interpretation belongs to whoever composes the message.
 */
final readonly class SystemNotificationPreference
{
    public function __construct(
        public string $eventType,
        public string $channel,
    ) {}
}
