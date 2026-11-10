<?php

declare(strict_types=1);

namespace Core\Notification\Contracts;

final readonly class SaveSystemNotificationSettingsCommand
{
    /**
     * @param list<SystemNotificationPreferenceView> $preferences the
     *                                                            declarations the project wants — the previous ones are replaced
     * @param string $actor "operator:<uuid>"
     */
    public function __construct(
        public string $projectId,
        public array $preferences,
        public string $actor,
        public ?string $correlationId = null,
    ) {}
}
