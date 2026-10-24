<?php

declare(strict_types=1);

namespace Core\Audit\Contracts;

final readonly class AuditDigestView
{
    public function __construct(
        public string $day,
        public int $entriesCount,
        public ?string $previousDigest,
        public string $digest,
    ) {}
}
