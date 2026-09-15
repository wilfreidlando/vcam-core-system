<?php

declare(strict_types=1);

namespace Core\Webhook\Domain\Exception;

use RuntimeException;

final class WebhookEndpointNotFound extends RuntimeException
{
    public static function withId(string $id): self
    {
        return new self(sprintf('Webhook endpoint "%s" was not found.', $id));
    }
}
