<?php

declare(strict_types=1);

namespace Core\Notification\Contracts;

/**
 * `status`, if given, is the raw canonical status string (e.g.
 * `"delivered"`). A `Core\Notification\NotificationStatus` value on this
 * DTO would make it unusable from outside the module (G3c —
 * `NotificationStatus` is top-level, not under `Contracts/`); the use case
 * converts it via `NotificationStatus::tryFrom()`, treating an
 * unrecognised value as "no filter" rather than an error.
 */
final readonly class ListNotificationsCommand
{
    public function __construct(
        public string $projectId,
        public ?string $status = null,
        public int $page = 1,
        public int $perPage = 25,
        // `live` / `sandbox`, raw string (G3c); null = both.
        public ?string $environment = null,
    ) {}
}
