<?php

declare(strict_types=1);

namespace Core\Payment\Tests\Unit\Fakes;

use Core\Payment\Contracts\NoProviderForChannelException;
use Core\Payment\Contracts\PaymentChannel;
use Core\Payment\Contracts\PaymentProviderRouter;
use Core\Support\Integration\Provider\ProviderCode;

final class FakePaymentProviderRouter implements PaymentProviderRouter
{
    /**
     * @param array<string, string> $channelToProviderCode
     */
    public function __construct(
        private readonly array $channelToProviderCode = ['mtn_momo' => 'my-coolpay'],
    ) {}

    public function resolveProvider(PaymentChannel $channel, string $projectId): ProviderCode
    {
        $code = $this->channelToProviderCode[$channel->value] ?? null;

        if ($code === null) {
            throw NoProviderForChannelException::forChannel($channel, $projectId);
        }

        return ProviderCode::fromString($code);
    }
}
