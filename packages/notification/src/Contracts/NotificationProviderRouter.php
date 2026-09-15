<?php

declare(strict_types=1);

namespace Core\Notification\Contracts;

use Core\Support\Integration\Provider\ProviderCode;

/**
 * Resolves the ordered fallback chain of providers for a given channel
 * and Project (spec §9.3, ADR-0041 §3) — plural and ordered, unlike
 * `Core\Payment\Contracts\PaymentProviderRouter::resolveProvider()`
 * (singular): a channel here maps to primary + fallbacks, tried
 * synchronously in order by `SendNotificationUseCase` until one succeeds.
 */
interface NotificationProviderRouter
{
    /**
     * @return list<ProviderCode> ordered primary-first; never empty
     *
     * @throws NoProviderForNotificationChannelException when no provider
     *                                                   chain (project override or platform default) serves this channel
     */
    public function resolveProviders(NotificationChannel $channel, string $projectId): array;
}
