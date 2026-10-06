<?php

declare(strict_types=1);

namespace Core\Payment\Contracts;

use RuntimeException;

interface RegisterProviderApplication
{
    /**
     * @return bool true when an existing application was updated (keys
     *              rotated), false when a new one was created
     *
     * @throws RuntimeException project unknown, public key already used
     *                          elsewhere (with an operator-readable message),
     *                          or a new application without a private key
     */
    public function register(RegisterProviderApplicationCommand $command): bool;

    public function delete(string $projectId, string $applicationId): void;
}
