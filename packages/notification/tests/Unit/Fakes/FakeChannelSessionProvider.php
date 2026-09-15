<?php

declare(strict_types=1);

namespace Core\Notification\Tests\Unit\Fakes;

use Core\Notification\Contracts\CallbackPayload;
use Core\Notification\Contracts\ChannelSessionProvider;
use Core\Notification\Contracts\ChannelSessionQrCode;
use Core\Notification\Contracts\ChannelSessionScreenshot;
use Core\Notification\Contracts\DeliveryStatus;
use Core\Notification\Contracts\NotificationChannel;
use Core\Notification\Contracts\NotificationChannelProvider;
use Core\Notification\Contracts\OutboundMessage;
use Core\Notification\Contracts\ProviderDispatchResult;
use Core\Notification\NotificationStatus;
use Core\Support\Integration\Configuration\ProviderConfig;
use Core\Support\Integration\Provider\ProviderCode;
use LogicException;

/**
 * A provider that implements both contracts — the shape `WahaAdapter`
 * has (ADR-0042 §2) — for use-case-level tests that don't want to spin
 * up a real HTTP pipeline.
 */
final class FakeChannelSessionProvider implements ChannelSessionProvider, NotificationChannelProvider
{
    /** @var list<string> */
    public array $startedSessions = [];

    public string $nextPairingCode = 'ABCD-ABCD';

    public ChannelSessionQrCode $nextQrCode;

    public ChannelSessionScreenshot $nextScreenshot;

    public function __construct(
        private readonly ProviderCode $code,
    ) {
        $this->nextQrCode = new ChannelSessionQrCode('image/png', 'fake-qr-base64');
        $this->nextScreenshot = new ChannelSessionScreenshot('image/png', 'fake-screenshot-base64');
    }

    public function startSession(string $sessionRef): void
    {
        $this->startedSessions[] = $sessionRef;
    }

    public function requestPairingCode(string $sessionRef, string $phoneNumber): string
    {
        return $this->nextPairingCode;
    }

    public function getQrCode(string $sessionRef): ChannelSessionQrCode
    {
        return $this->nextQrCode;
    }

    public function getScreenshot(string $sessionRef): ChannelSessionScreenshot
    {
        return $this->nextScreenshot;
    }

    public function send(OutboundMessage $message): ProviderDispatchResult
    {
        return new ProviderDispatchResult('msg-fake-1', NotificationStatus::Sent);
    }

    public function getStatus(string $providerMessageId): DeliveryStatus
    {
        return new DeliveryStatus(NotificationStatus::Sent);
    }

    public function handleDeliveryCallback(CallbackPayload $payload): DeliveryStatus
    {
        return new DeliveryStatus(NotificationStatus::Sent);
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
