<?php

declare(strict_types=1);

namespace Core\Notification\Tests\Unit\Fakes;

use Core\Notification\Contracts\CallbackPayload;
use Core\Notification\Contracts\DeliveryStatus;
use Core\Notification\Contracts\NotificationChannel;
use Core\Notification\Contracts\NotificationChannelProvider;
use Core\Notification\Contracts\OutboundMessage;
use Core\Notification\Contracts\ProviderDispatchResult;
use Core\Notification\NotificationStatus;
use Core\Support\Integration\Configuration\ProviderConfig;
use Core\Support\Integration\Provider\ProviderCode;
use LogicException;

final class FakeNotificationChannelProvider implements NotificationChannelProvider
{
    /** @var list<OutboundMessage> */
    public array $sent = [];

    public ProviderDispatchResult $nextResult;

    /** @var (callable(): ProviderDispatchResult)|null */
    public $onSend = null;

    public function __construct(
        private readonly ProviderCode $code,
    ) {
        $this->nextResult = new ProviderDispatchResult('msg-fake-1', NotificationStatus::Sent);
    }

    public function send(OutboundMessage $message): ProviderDispatchResult
    {
        $this->sent[] = $message;

        if ($this->onSend !== null) {
            return ($this->onSend)();
        }

        return $this->nextResult;
    }

    public function getStatus(string $providerMessageId): DeliveryStatus
    {
        return new DeliveryStatus($this->nextResult->status);
    }

    public function handleDeliveryCallback(CallbackPayload $payload): DeliveryStatus
    {
        return new DeliveryStatus($this->nextResult->status);
    }

    public function supports(NotificationChannel $channel, string $country): bool
    {
        return true;
    }

    public function providerCode(): ProviderCode
    {
        return $this->code;
    }

    public function config(): ProviderConfig
    {
        throw new LogicException('Not used by this fake.');
    }
}
