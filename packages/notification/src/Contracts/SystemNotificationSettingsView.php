<?php

declare(strict_types=1);

namespace Core\Notification\Contracts;

/**
 * What a project declared it wants its customers notified about
 * (ADR-0118 §4). An empty list of preferences means the project declared
 * nothing — and then nothing is sent.
 */
final readonly class SystemNotificationSettingsView
{
    /**
     * @param list<SystemNotificationPreferenceView> $preferences
     */
    public function __construct(
        public string $projectId,
        public array $preferences,
    ) {}

    /**
     * Whether the project declared this event on this channel.
     */
    public function wants(string $eventType, string $channel): bool
    {
        foreach ($this->preferences as $preference) {
            if ($preference->eventType === $eventType && $preference->channel === $channel) {
                return true;
            }
        }

        return false;
    }
}
