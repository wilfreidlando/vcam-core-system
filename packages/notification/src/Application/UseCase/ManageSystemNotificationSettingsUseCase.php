<?php

declare(strict_types=1);

namespace Core\Notification\Application\UseCase;

use Core\Notification\Contracts\ManageSystemNotificationSettings;
use Core\Notification\Contracts\NotificationChannel;
use Core\Notification\Contracts\SaveSystemNotificationSettingsCommand;
use Core\Notification\Contracts\SystemNotificationPreferenceView;
use Core\Notification\Contracts\SystemNotificationSettingsView;
use Core\Notification\Domain\Model\SystemNotificationPreference;
use Core\Notification\Domain\Port\SystemNotificationSettingsRepository;
use Core\Support\Access\AccessScope;

/**
 * ADR-0118 §4 — the declarations of a project: which events it wants its
 * customers notified about, on which channel.
 *
 * The module stores them without interpreting them: the events are the
 * commerce's words, and it is the composition (ADR-0118 §3) that decides
 * what to say.
 */
final readonly class ManageSystemNotificationSettingsUseCase implements ManageSystemNotificationSettings
{
    public function __construct(
        private SystemNotificationSettingsRepository $settings,
    ) {}

    public function get(string $projectId, AccessScope $scope): ?SystemNotificationSettingsView
    {
        if (! $scope->allowsProject($projectId)) {
            return null;
        }

        return $this->toView($projectId, $this->settings->listFor($projectId));
    }

    public function save(SaveSystemNotificationSettingsCommand $command, AccessScope $scope): ?SystemNotificationSettingsView
    {
        if (! $scope->allowsProject($command->projectId)) {
            return null;
        }

        $preferences = $this->normalize($command->preferences);

        $this->settings->replace($command->projectId, $preferences);

        return $this->toView($command->projectId, $preferences);
    }

    /**
     * The declarations as the module stores them: one per (event, channel),
     * the duplicates removed, the order kept.
     *
     * @param list<SystemNotificationPreferenceView> $preferences
     * @return list<SystemNotificationPreference>
     */
    private function normalize(array $preferences): array
    {
        $normalized = [];
        $seen = [];

        foreach ($preferences as $preference) {
            $eventType = trim($preference->eventType);
            $channel = trim($preference->channel);

            if ($eventType === '' || $channel === '') {
                continue;
            }

            // An unknown channel is not a declaration: it would be stored
            // and never routed.
            NotificationChannel::fromString($channel);

            $key = $eventType.'|'.$channel;
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $normalized[] = new SystemNotificationPreference($eventType, $channel);
        }

        return $normalized;
    }

    /**
     * @param list<SystemNotificationPreference> $preferences
     */
    private function toView(string $projectId, array $preferences): SystemNotificationSettingsView
    {
        $views = [];

        foreach ($preferences as $preference) {
            $views[] = new SystemNotificationPreferenceView($preference->eventType, $preference->channel);
        }

        return new SystemNotificationSettingsView($projectId, $views);
    }
}
