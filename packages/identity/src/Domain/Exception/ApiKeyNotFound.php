<?php

declare(strict_types=1);

namespace Core\Identity\Domain\Exception;

use RuntimeException;

final class ApiKeyNotFound extends RuntimeException
{
    public static function withId(string $id): self
    {
        return new self("API key {$id} does not exist.");
    }
}
