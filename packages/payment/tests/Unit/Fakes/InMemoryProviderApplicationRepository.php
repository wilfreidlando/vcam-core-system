<?php

declare(strict_types=1);

namespace Core\Payment\Tests\Unit\Fakes;

use Core\Payment\Domain\Exception\ProviderApplicationPublicKeyAlreadyUsed;
use Core\Payment\Domain\Model\ProviderApplication;
use Core\Payment\Domain\Port\ProviderApplicationRepository;

final class InMemoryProviderApplicationRepository implements ProviderApplicationRepository
{
    /** @var array<string, ProviderApplication> */
    private array $applications = [];

    /**
     * @param list<ProviderApplication> $seed
     */
    public function __construct(array $seed = [])
    {
        foreach ($seed as $application) {
            $this->save($application);
        }
    }

    public function save(ProviderApplication $application): void
    {
        $owner = $this->findByProviderAndPublicKey($application->providerCode, $application->publicKey);
        if ($owner !== null && $owner->projectId !== $application->projectId) {
            throw new ProviderApplicationPublicKeyAlreadyUsed($owner->projectId);
        }

        $key = self::key($application->projectId, $application->providerCode);
        $id = $application->id ?? ($this->applications[$key]->id ?? 'app-'.(count($this->applications) + 1));

        $this->applications[$key] = new ProviderApplication(
            projectId: $application->projectId,
            providerCode: $application->providerCode,
            publicKey: $application->publicKey,
            privateKey: $application->privateKey,
            id: $id,
            label: $application->label,
        );
    }

    public function findByProjectAndProvider(string $projectId, string $providerCode): ?ProviderApplication
    {
        return $this->applications[self::key($projectId, $providerCode)] ?? null;
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

    public function find(string $id): ?ProviderApplication
    {
        foreach ($this->applications as $application) {
            if ($application->id === $id) {
                return $application;
            }
        }

        return null;
    }

    public function delete(string $id): void
    {
        foreach ($this->applications as $key => $application) {
            if ($application->id === $id) {
                unset($this->applications[$key]);
            }
        }
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

    private static function key(string $projectId, string $providerCode): string
    {
        return $providerCode.':'.$projectId;
    }
}
