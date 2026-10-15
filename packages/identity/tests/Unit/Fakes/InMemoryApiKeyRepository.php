<?php

declare(strict_types=1);

namespace Core\Identity\Tests\Unit\Fakes;

use Core\Identity\Domain\Model\ApiKey;
use Core\Identity\Domain\Port\ApiKeyRepository;
use DateTimeImmutable;

final class InMemoryApiKeyRepository implements ApiKeyRepository
{
    /** @var array<string, ApiKey> */
    private array $apiKeys = [];

    public function save(ApiKey $apiKey): void
    {
        $this->apiKeys[$apiKey->id] = $apiKey;
    }

    public function find(string $id): ?ApiKey
    {
        return $this->apiKeys[$id] ?? null;
    }

    public function findByPrefix(string $prefix): ?ApiKey
    {
        foreach ($this->apiKeys as $apiKey) {
            if ($apiKey->prefix === $prefix) {
                return $apiKey;
            }
        }

        return null;
    }

    public function findAllByProject(string $projectId): array
    {
        return array_values(array_filter(
            $this->apiKeys,
            static fn (ApiKey $apiKey): bool => $apiKey->projectId === $projectId,
        ));
    }

    public function findOneByProject(string $projectId): ?ApiKey
    {
        foreach ($this->apiKeys as $key) {
            if ($key->projectId === $projectId) {
                return $key;
            }
        }

        return null;
    }

    public function delete(string $id): void
    {
        unset($this->apiKeys[$id]);
    }

    public function touchLastUsed(string $id, DateTimeImmutable $at): void {}
}
