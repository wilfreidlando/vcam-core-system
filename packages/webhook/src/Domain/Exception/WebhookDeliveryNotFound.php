<?php

declare(strict_types=1);

namespace Core\Webhook\Domain\Exception;

use RuntimeException;

final class WebhookDeliveryNotFound extends RuntimeException
{
    public static function withId(string $id): self
    {
        return new self(sprintf('Webhook delivery "%s" was not found.', $id));
    }
}
