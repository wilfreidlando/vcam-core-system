<?php

declare(strict_types=1);

namespace Core\Notification\Contracts;

use Core\Notification\NotificationStatus;

/**
 * What a provider returned for one `send()` call — mirrors
 * `Core\Payment\Contracts\PaymentReceipt`'s role: the canonical result an
 * adapter hands back, never a raw provider payload.
 */
final readonly class ProviderDispatchResult
{
    public function __construct(
        public string $providerMessageId,
        public NotificationStatus $status,
        public ?string $errorMessage = null,
    ) {}
}
