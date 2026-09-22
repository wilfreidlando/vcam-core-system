<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

use RuntimeException;

interface RevokeApiKey
{
    /**
     * @throws RuntimeException if `$command->apiKeyId` doesn't resolve to
     *                          an existing ApiKey.
     */
    public function revoke(RevokeApiKeyCommand $command, string $actor, ?string $correlationId): void;
}
