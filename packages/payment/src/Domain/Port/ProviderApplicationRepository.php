<?php

declare(strict_types=1);

namespace Core\Payment\Domain\Port;

use Core\Payment\Domain\Exception\ProviderApplicationPublicKeyAlreadyUsed;
use Core\Payment\Domain\Model\ProviderApplication;

interface ProviderApplicationRepository
{
    /**
     * Creates, or replaces the keys of, the application of
     * (project, provider).
     *
     * @throws ProviderApplicationPublicKeyAlreadyUsed when the public key
     *                                                 already routes another project
     */
    public function save(ProviderApplication $application): void;

    public function findByProjectAndProvider(string $projectId, string $providerCode): ?ProviderApplication;

    public function findByProviderAndPublicKey(string $providerCode, string $publicKey): ?ProviderApplication;

    public function find(string $id): ?ProviderApplication;

    public function delete(string $id): void;

    /**
     * @return list<ProviderApplication>
     */
    public function findAllByProject(string $projectId): array;
}
