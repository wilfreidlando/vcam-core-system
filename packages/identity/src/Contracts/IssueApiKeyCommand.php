<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

final readonly class IssueApiKeyCommand
{
    /**
     * @param list<string> $scopes
     */
    public function __construct(
        public string $projectId,
        public array $scopes,
        // ADR-0066: true = regenerate (atomically delete the project's
        // existing key, then issue the new one); false = refuse if one exists.
        public bool $replaceExisting = false,
    ) {}
}
