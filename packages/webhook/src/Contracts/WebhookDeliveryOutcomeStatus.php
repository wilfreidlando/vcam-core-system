<?php

declare(strict_types=1);

namespace Core\Webhook\Contracts;

enum WebhookDeliveryOutcomeStatus
{
    case Delivered;
    case RetryScheduled;
    case Dead;
}
