<?php

declare(strict_types=1);

namespace Core\Identity\Domain\Exception;

use RuntimeException;

final class OrganizationNotFound extends RuntimeException
{
    public static function withId(string $id): self
    {
        return new self("Organization {$id} does not exist.");
    }
}
