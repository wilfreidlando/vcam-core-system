<?php

declare(strict_types=1);

namespace Core\Notification\Tests\Unit\Fakes;

use Core\Notification\Contracts\NoProviderForNotificationChannelException;
use Core\Notification\Contracts\NotificationChannel;
use Core\Notification\Contracts\NotificationProviderRouter;
use Core\Support\Integration\Provider\ProviderCode;

final class FakeNotificationProviderRouter implements NotificationProviderRouter
{
    /** @var list<ProviderCode> */
    public array $chain;

    /**
     * @param list<string> $providerCodes ordered primary-first
     */
    public function __construct(array $providerCodes = ['fake-provider'])
    {
        $this->chain = array_map(static fn (string $code): ProviderCode => ProviderCode::fromString($code), $providerCodes);
    }

    public function resolveProviders(NotificationChannel $channel, string $projectId): array
    {
        if ($this->chain === []) {
            throw NoProviderForNotificationChannelException::forChannel($channel, $projectId);
        }

        return $this->chain;
    }
}
