<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

use Core\Support\Environment;

final readonly class IssueApiKeyCommand
{
    /**
     * @param list<string> $scopes
     */
    public function __construct(
        public string $projectId,
        public Environment $environment,
        public array $scopes,
        // ADR-0060: true = regenerate (atomically delete the environment's
        // existing key, then issue the new one); false = refuse if one exists.
        public bool $replaceExisting = false,
    ) {}
}
