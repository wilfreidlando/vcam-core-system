<?php

declare(strict_types=1);

namespace Core\Notification\Infrastructure\Persistence\Eloquent;

use Core\Notification\Domain\Model\SystemNotificationPreference;
use Core\Notification\Domain\Port\SystemNotificationSettingsRepository;

final class EloquentSystemNotificationSettingsRepository implements SystemNotificationSettingsRepository
{
    /**
     * @return list<SystemNotificationPreference>
     */
    public function listFor(string $projectId): array
    {
        $record = SystemNotificationSettingsRecord::query()
            ->where('project_id', $projectId)
            ->first();

        if ($record === null) {
            return [];
        }

        return $this->toDomain($record);
    }

    /**
     * @param list<SystemNotificationPreference> $preferences
     */
    public function replace(string $projectId, array $preferences): void
    {
        SystemNotificationSettingsRecord::query()->updateOrCreate(
            ['project_id' => $projectId],
            ['preferences' => $this->toAttributes($preferences)],
        );
    }

    /**
     * @return list<SystemNotificationPreference>
     */
    private function toDomain(SystemNotificationSettingsRecord $record): array
    {
        $preferences = [];

        foreach ($record->preferences as $preference) {
            if (! is_array($preference)) {
                continue;
            }

            $eventType = $preference['event_type'] ?? null;
            $channel = $preference['channel'] ?? null;

            if (! is_string($eventType) || $eventType === '' || ! is_string($channel) || $channel === '') {
                continue;
            }

            $preferences[] = new SystemNotificationPreference($eventType, $channel);
        }

        return $preferences;
    }

    /**
     * @param list<SystemNotificationPreference> $preferences
     * @return list<array{event_type: string, channel: string}>
     */
    private function toAttributes(array $preferences): array
    {
        $attributes = [];

        foreach ($preferences as $preference) {
            $attributes[] = [
                'event_type' => $preference->eventType,
                'channel' => $preference->channel,
            ];
        }

        return $attributes;
    }
}
