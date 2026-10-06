<?php

declare(strict_types=1);

namespace Core\Identity\Application\UseCase;

use Core\Identity\Contracts\ProjectContext;
use Core\Identity\Domain\Exception\InvalidApiKey;
use Core\Identity\Domain\Exception\ProjectNotActive;
use Core\Identity\Domain\Model\ApiKeyToken;
use Core\Identity\Domain\Port\ApiKeyRepository;
use Core\Identity\Domain\Port\ProjectRepository;
use Core\Support\Clock;

/**
 * The authentication path every capability request runs through
 * (`ResolveProjectMiddleware` calls this once per request). Deliberately
 * unaudited: `audit_log` records sensitive administrative actions, not every
 * authenticated call — that volume belongs in structured request logs,
 * already correlated by `Core\Support\Http\Middleware\CorrelationId`.
 */
final readonly class ResolveProjectFromBearerToken
{
    public function __construct(
        private ApiKeyRepository $apiKeys,
        private ProjectRepository $projects,
        private Clock $clock,
    ) {}

    public function __invoke(string $bearerToken): ProjectContext
    {
        $token = ApiKeyToken::parse($bearerToken);

        $apiKey = $this->apiKeys->findByPrefix($token->prefix)
            ?? throw InvalidApiKey::unknown();

        if (! $apiKey->matchesSecret($token->secret)) {
            throw InvalidApiKey::unknown();
        }

        if ($apiKey->isRevoked()) {
            throw InvalidApiKey::revoked();
        }

        $project = $this->projects->find($apiKey->projectId)
            ?? throw InvalidApiKey::unknown(); // orphaned key: treat as invalid, not a 500.

        if (! $project->isActive()) {
            throw ProjectNotActive::withId($project->id);
        }

        $this->apiKeys->touchLastUsed($apiKey->id, $this->clock->now());

        return new ProjectContext(
            projectId: $project->id,
            organizationId: $project->organizationId,
            environment: $apiKey->environment,
            scopes: $apiKey->scopes(),
            apiKeyPrefix: $apiKey->prefix,
        );
    }
}
