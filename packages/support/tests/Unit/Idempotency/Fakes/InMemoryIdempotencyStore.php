<?php

declare(strict_types=1);

namespace Core\Support\Tests\Unit\Idempotency\Fakes;

use Core\Support\Idempotency\IdempotencyKeyAlreadyLocked;
use Core\Support\Idempotency\IdempotencyRecord;
use Core\Support\Idempotency\IdempotencyStore;

final class InMemoryIdempotencyStore implements IdempotencyStore
{
    /** @var array<string, IdempotencyRecord> */
    private array $records = [];

    public function find(string $projectId, string $key): ?IdempotencyRecord
    {
        return $this->records["{$projectId}:{$key}"] ?? null;
    }

    public function lock(string $projectId, string $key, string $requestHash): void
    {
        $storeKey = "{$projectId}:{$key}";
        if (isset($this->records[$storeKey])) {
            throw new IdempotencyKeyAlreadyLocked;
        }

        $this->records[$storeKey] = new IdempotencyRecord($requestHash, completed: false, responseStatus: null, responseBody: null);
    }

    public function complete(string $projectId, string $key, int $responseStatus, array $responseBody): void
    {
        $storeKey = "{$projectId}:{$key}";
        $requestHash = isset($this->records[$storeKey]) ? $this->records[$storeKey]->requestHash : '';

        $this->records[$storeKey] = new IdempotencyRecord($requestHash, completed: true, responseStatus: $responseStatus, responseBody: $responseBody);
    }
}
