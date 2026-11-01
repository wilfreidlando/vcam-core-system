<?php

declare(strict_types=1);

namespace Core\Webhook\Domain\Exception;

use RuntimeException;

final class InvalidWebhookSecretRotation extends RuntimeException
{
    public static function overlap(int $min, int $max): self
    {
        return new self(sprintf('The overlap must be between %d and %d hours.', $min, $max));
    }
}
