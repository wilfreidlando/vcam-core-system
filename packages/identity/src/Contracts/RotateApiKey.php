<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

use RuntimeException;

interface RotateApiKey
{
    /**
     * Issues the replacement (its secret shown once, in the returned view)
     * and schedules the old key's end.
     *
     * @throws RuntimeException unknown key, key already rotated, inactive
     *                          project, or grace period out of range
     */
    public function rotate(RotateApiKeyCommand $command, string $actor, ?string $correlationId): IssuedApiKeyView;
}
