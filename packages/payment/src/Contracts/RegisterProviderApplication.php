<?php

declare(strict_types=1);

namespace Core\Payment\Contracts;

use Core\Support\Access\AccessScope;
use RuntimeException;

interface RegisterProviderApplication
{
    /**
     * The scope is required (ADR-0069 §2): registering a provider
     * application writes credentials on a project, so the project must be
     * one the caller may see.
     *
     *
     * @return bool true when an existing application was updated (keys
     *              rotated), false when a new one was created
     *
     * @throws RuntimeException project unknown, public key already used
     *                          elsewhere (with an operator-readable message),
     *                          or a new application without a private key
     */
    public function register(RegisterProviderApplicationCommand $command, AccessScope $scope): bool;

    public function delete(string $projectId, string $applicationId, AccessScope $scope): void;
}
