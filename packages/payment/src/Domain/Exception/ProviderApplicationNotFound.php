<?php

declare(strict_types=1);

namespace Core\Payment\Domain\Exception;

use RuntimeException;

/**
 * No application/merchant-account is registered for the given Project
 * and provider (or, on the inbound path, for the given public key).
 * There is deliberately no fallback to a shared/default key — a Project
 * without its own registered application simply cannot pay through that
 * provider yet.
 */
final class ProviderApplicationNotFound extends RuntimeException
{
    public static function forProject(string $projectId, string $providerCode): self
    {
        return new self("No {$providerCode} application is registered for project {$projectId}.");
    }

    public static function withId(string $id): self
    {
        return new self("Provider application {$id} not found.");
    }

    public static function forPublicKey(string $providerCode, string $publicKey): self
    {
        return new self("No {$providerCode} application is registered for public key {$publicKey}.");
    }
}
