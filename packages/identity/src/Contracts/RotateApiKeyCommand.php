<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

/**
 * ADR-0082 §3 — guided rotation: a new key with the same name, scopes and
 * network restriction; the old one stays valid `graceDays` more days.
 */
final readonly class RotateApiKeyCommand
{
    public const MIN_GRACE_DAYS = 1;

    public const MAX_GRACE_DAYS = 30;

    public const DEFAULT_GRACE_DAYS = 7;

    public function __construct(
        public string $apiKeyId,
        // When given, the key must belong to this project (same "not found" otherwise).
        public ?string $projectId = null,
        public int $graceDays = self::DEFAULT_GRACE_DAYS,
    ) {}
}
