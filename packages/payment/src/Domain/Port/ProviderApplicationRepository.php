<?php

declare(strict_types=1);

namespace Core\Payment\Domain\Port;

use Core\Payment\Domain\Model\ProviderApplication;

interface ProviderApplicationRepository
{
    /**
     * Creates, or replaces the keys of, the application of
     * (project, provider).
     */
    public function save(ProviderApplication $application): void;

    public function findByProjectAndProvider(string $projectId, string $providerCode): ?ProviderApplication;

    /**
     * Every application registered with this public key for this
     * provider.
     *
     * A public key may serve several projects (ADR-0126): it is no
     * longer a routing key on its own. The list is what an inbound
     * callback resolves itself against — the project of the payment it
     * names decides which of them applies.
     *
     * @return list<ProviderApplication>
     */
    public function findAllByProviderAndPublicKey(string $providerCode, string $publicKey): array;

    public function find(string $id): ?ProviderApplication;

    public function delete(string $id): void;

    /**
     * @return list<ProviderApplication>
     */
    public function findAllByProject(string $projectId): array;
}
