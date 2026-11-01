<?php

declare(strict_types=1);

namespace Core\Payment\Domain\Exception;

use RuntimeException;

final class MoneyExceptionNotFound extends RuntimeException
{
    public static function withId(string $id): self
    {
        return new self(sprintf('Exception d\'argent %s introuvable.', $id));
    }
}
