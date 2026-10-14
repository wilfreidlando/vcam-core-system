<?php

declare(strict_types=1);

namespace Core\Parties\Contracts;

/**
 * Lives in Contracts, not Application: the caller is almost always another
 * module (Wallet, and later Payment/Notification), which may only depend on
 * this module through Contracts/ (G3c).
 */
final readonly class FindOrCreatePartyCommand
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $projectId,
        public string $externalRef,
        public string $type,
        public array $metadata = [],
        // ADR-0065 — `live` or `sandbox`, the environment of the calling key.
        public string $environment = 'live',
    ) {}
}
