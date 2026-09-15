<?php

declare(strict_types=1);

namespace Core\Payment\Infrastructure\Routing;

use Core\Payment\Contracts\NoProviderForChannelException;
use Core\Payment\Contracts\PaymentChannel;
use Core\Payment\Contracts\PaymentProviderRouter;
use Core\Support\Integration\Provider\ProviderCode;

/**
 * Reads `config/payment_channels.php` — deliberately a flat array, not a
 * database table, per ADR-0038 §2: routing stays simple until a second
 * real provider and a real need to diverge per Project both exist. A
 * Project override takes precedence over the platform default for the
 * same channel; extending this to something richer (cost, geography,
 * failover) is the config's job to grow into, not this class's.
 *
 * @phpstan-type ChannelMap array<string, string>
 */
final readonly class ConfigPaymentProviderRouter implements PaymentProviderRouter
{
    /**
     * @param ChannelMap $defaults channel => provider_code
     * @param array<string, ChannelMap> $projectOverrides project_id => (channel => provider_code)
     */
    public function __construct(
        private array $defaults,
        private array $projectOverrides = [],
    ) {}

    public function resolveProvider(PaymentChannel $channel, string $projectId): ProviderCode
    {
        $code = $this->projectOverrides[$projectId][$channel->value]
            ?? $this->defaults[$channel->value]
            ?? null;

        if ($code === null) {
            throw NoProviderForChannelException::forChannel($channel, $projectId);
        }

        return ProviderCode::fromString($code);
    }
}
