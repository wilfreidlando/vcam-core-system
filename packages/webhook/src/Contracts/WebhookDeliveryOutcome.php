<?php

declare(strict_types=1);

namespace Core\Webhook\Contracts;

/**
 * `retryAfterSeconds` is populated only when `status` is `RetryScheduled` —
 * `Presentation\Jobs\DeliverWebhookJob` reads it to call `$this->release()`.
 */
final readonly class WebhookDeliveryOutcome
{
    public function __construct(
        public WebhookDeliveryOutcomeStatus $status,
        public ?int $retryAfterSeconds,
    ) {}
}
