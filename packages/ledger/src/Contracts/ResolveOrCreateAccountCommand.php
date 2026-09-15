<?php

declare(strict_types=1);

namespace Core\Ledger\Contracts;

final readonly class ResolveOrCreateAccountCommand
{
    /**
     * @param string $kind Lowercase, underscore-separated (e.g. "wallet", "clearing").
     * @param string $reference The kind-specific identifier (e.g. a wallet id), or "" for a project-level account.
     */
    public function __construct(
        public string $projectId,
        public string $kind,
        public string $reference,
        public string $currency,
    ) {}
}
