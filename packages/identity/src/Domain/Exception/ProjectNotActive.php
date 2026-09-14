<?php

declare(strict_types=1);

namespace Core\Identity\Domain\Exception;

use RuntimeException;

/**
 * Raised when a request authenticates with valid credentials belonging to a
 * suspended or archived project. Distinct from `InvalidApiKey` (bad
 * credentials) because the caller's response should say so plainly rather
 * than look like a wrong or leaked key.
 */
final class ProjectNotActive extends RuntimeException
{
    public static function withId(string $id): self
    {
        return new self("Project {$id} is not active.");
    }
}
