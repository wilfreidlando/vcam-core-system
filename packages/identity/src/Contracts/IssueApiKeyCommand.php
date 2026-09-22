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
    ) {}
}
