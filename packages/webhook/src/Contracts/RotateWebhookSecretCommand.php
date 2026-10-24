<?php

declare(strict_types=1);

namespace Core\Webhook\Contracts;

/**
 * ADR-0082 §5 — a new signing secret; the previous one keeps signing
 * (`X-Signature-Previous`) for `overlapHours`.
 */
final readonly class RotateWebhookSecretCommand
{
    public const MIN_OVERLAP_HOURS = 1;

    public const MAX_OVERLAP_HOURS = 72;

    public const DEFAULT_OVERLAP_HOURS = 24;

    public function __construct(
        public string $projectId,
        public string $endpointId,
        public int $overlapHours = self::DEFAULT_OVERLAP_HOURS,
    ) {}
}
