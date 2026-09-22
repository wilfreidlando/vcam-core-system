<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

use RuntimeException;

interface IssueApiKey
{
    /**
     * @throws RuntimeException if `$command->projectId` doesn't resolve to
     *                          an existing, active Project. The concrete exception types live
     *                          in the Domain layer; Contracts must not reference them (G3c).
     */
    public function issue(IssueApiKeyCommand $command, string $actor, ?string $correlationId): IssuedApiKeyView;
}
