<?php

declare(strict_types=1);

namespace Core\Payment\Tests\Unit\Fakes;

use Core\Payment\Domain\Model\ProviderApplication;
use Core\Payment\Domain\Port\ProviderApplicationRepository;

final class InMemoryProviderApplicationRepository implements ProviderApplicationRepository
{
    /** @var array<string, ProviderApplication> */
    private array $applications = [];

    public function save(ProviderApplication $application): void
    {
        $this->applications[$application->providerCode.':'.$application->projectId] = $application;
    }

    public function findByProjectAndProvider(string $projectId, string $providerCode): ?ProviderApplication
    {
        return $this->applications[$providerCode.':'.$projectId] ?? null;
    }

    public function findByProviderAndPublicKey(string $providerCode, string $publicKey): ?ProviderApplication
    {
        foreach ($this->applications as $application) {
            if ($application->providerCode === $providerCode && $application->publicKey === $publicKey) {
                return $application;
            }
        }

        return null;
    }

    public function findAllByProject(string $projectId): array
    {
        $applications = array_values(array_filter(
            $this->applications,
            static fn (ProviderApplication $application): bool => $application->projectId === $projectId,
        ));

        usort($applications, static fn (ProviderApplication $a, ProviderApplication $b): int => $a->providerCode <=> $b->providerCode);

        return $applications;
    }
}
