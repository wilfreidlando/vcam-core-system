<?php

declare(strict_types=1);

namespace Core\Notification\Infrastructure\Routing;

use Core\Notification\Contracts\NoProviderForNotificationChannelException;
use Core\Notification\Contracts\NotificationChannel;
use Core\Notification\Contracts\NotificationProviderRouter;
use Core\Support\Integration\Provider\ProviderCode;

/**
 * Reads `config/notification_channels.php` — mirrors
 * `Core\Payment\Infrastructure\Routing\ConfigPaymentProviderRouter`, but
 * each channel maps to an ordered **list** of provider codes
 * (primary + fallbacks, ADR-0041 §3) instead of a single one.
 *
 * @phpstan-type ChannelMap array<string, list<string>>
 */
final readonly class ConfigNotificationProviderRouter implements NotificationProviderRouter
{
    /**
     * @param ChannelMap $defaults channel => ordered list of provider_code
     * @param array<string, ChannelMap> $projectOverrides project_id => (channel => ordered list)
     */
    public function __construct(
        private array $defaults,
        private array $projectOverrides = [],
    ) {}

    public function resolveProviders(NotificationChannel $channel, string $projectId): array
    {
        $codes = $this->projectOverrides[$projectId][$channel->value]
            ?? $this->defaults[$channel->value]
            ?? [];

        if ($codes === []) {
            throw NoProviderForNotificationChannelException::forChannel($channel, $projectId);
        }

        return array_map(
            static fn (string $code): ProviderCode => ProviderCode::fromString($code),
            $codes,
        );
    }
}
