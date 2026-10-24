<?php

declare(strict_types=1);

namespace Core\Identity\Application\UseCase;

use Core\Identity\Contracts\ProjectContext;
use Core\Identity\Domain\Exception\ApiKeyOriginNotAllowed;
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

    /**
     * @param string|null $clientIp the address resolved through the trusted
     *                              proxies (never a raw `X-Forwarded-For`)
     *
     * @throws InvalidApiKey unknown, revoked or expired key (401)
     * @throws ApiKeyOriginNotAllowed valid key, address outside its blocks (403)
     */
    public function __invoke(string $bearerToken, ?string $clientIp = null): ProjectContext
    {
        $token = ApiKeyToken::parse($bearerToken);

        $apiKey = $this->apiKeys->findByPrefix($token->prefix)
            ?? throw InvalidApiKey::unknown();

        if (! $apiKey->matchesSecret($token->secret)) {
            throw InvalidApiKey::unknown();
        }

        $now = $this->clock->now();
        if (! $apiKey->isUsableAt($now)) {
            // Revoked or expired: the same 401 either way (no enumeration).
            throw InvalidApiKey::revoked();
        }

        $project = $this->projects->find($apiKey->projectId)
            ?? throw InvalidApiKey::unknown(); // orphaned key: treat as invalid, not a 500.

        if (! $project->isActive()) {
            throw ProjectNotActive::withId($project->id);
        }

        if (! $apiKey->allowsOrigin($clientIp)) {
            throw new ApiKeyOriginNotAllowed($apiKey->id, $apiKey->prefix, $project->id, $clientIp);
        }

        $this->apiKeys->touchLastUsed($apiKey->id, $now, $clientIp);

        return new ProjectContext(
            projectId: $project->id,
            organizationId: $project->organizationId,
            scopes: $apiKey->scopes(),
            apiKeyPrefix: $apiKey->prefix,
            apiKeyId: $apiKey->id,
        );
    }
}
