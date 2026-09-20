<?php

declare(strict_types=1);

namespace Core\Payment\Domain\Port;

use Core\Payment\Domain\Model\ProviderApplication;

interface ProviderApplicationRepository
{
    public function save(ProviderApplication $application): void;

    /**
     * Resolved on the outbound path — a Project initiating (or checking
     * the status of) a payment, before the adapter knows which
     * public/private key pair to use.
     */
    public function findByProjectAndProvider(string $projectId, string $providerCode): ?ProviderApplication;

    /**
     * Resolved on the inbound path — a provider's callback carries only
     * its own `application`/public key, never the Project id. One
     * `publicKey` identifies exactly one application platform-wide
     * (enforced by a unique constraint), which is how the callback
     * authenticator finds the right private key to verify against.
     */
    public function findByProviderAndPublicKey(string $providerCode, string $publicKey): ?ProviderApplication;
}
