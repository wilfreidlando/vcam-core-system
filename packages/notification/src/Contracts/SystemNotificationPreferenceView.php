<?php

declare(strict_types=1);

namespace Core\Notification\Contracts;

/**
 * One declaration of a project: « cet événement, sur ce canal » (ADR-0118 §4).
 *
 * The module knows neither the events nor the channels it stores — it keeps
 * the strings the project declared, and never interprets them.
 */
final readonly class SystemNotificationPreferenceView
{
    public function __construct(
        public string $eventType,
        public string $channel,
    ) {}
}
