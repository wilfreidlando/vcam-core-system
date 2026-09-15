<?php

declare(strict_types=1);

namespace Core\Notification\Contracts;

use Core\Support\Integration\Provider\ProviderCode;
use Core\Support\Integration\Provider\ProviderGateway;

/**
 * Port implemented by every notification channel adapter (spec §9.2).
 *
 * Extends the generic `ProviderGateway` (`Core\Support\Integration\Provider`)
 * exactly like `Core\Payment\Contracts\PaymentGateway` does — the same
 * registry mechanism indexes both, sharing one `ProviderCode` namespace
 * (ADR-0041 §2). No concrete implementation exists yet; `send()` is
 * called by `SendNotificationUseCase`'s fallback cascade, one provider at
 * a time, until one succeeds or the chain is exhausted.
 */
interface NotificationChannelProvider extends ProviderGateway
{
    public function send(OutboundMessage $message): ProviderDispatchResult;

    /**
     * Active poll for the current status — used when no callback has
     * arrived within the expected window (mirrors
     * `Core\Payment\Contracts\PaymentGateway::checkStatus()`).
     */
    public function getStatus(string $providerMessageId): DeliveryStatus;

    /**
     * Translate one raw inbound callback into a canonical status. Called
     * by the adapter's own webhook controller (spec §9.5 §2) — none
     * exists in the Core yet (ADR-0041 §6).
     */
    public function handleDeliveryCallback(CallbackPayload $payload): DeliveryStatus;

    public function supports(NotificationChannel $channel, string $country): bool;

    public function providerCode(): ProviderCode;
}
