<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

use DateTimeImmutable;

final readonly class IssueApiKeyCommand
{
    /**
     * @param list<string> $scopes chosen at issuance (ADR-0082 §2), see `ApiKeyScopes`
     * @param list<string> $allowedCidrs optional network restriction (§4); empty = any address
     */
    public function __construct(
        public string $projectId,
        public array $scopes,
        public string $name = 'Clé principale',
        public array $allowedCidrs = [],
        public ?DateTimeImmutable $expiresAt = null,
    ) {}
}
