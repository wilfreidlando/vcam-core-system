<?php

declare(strict_types=1);

namespace Core\Identity\Application\UseCase;

use Core\Audit\Contracts\AuditEntry;
use Core\Audit\Contracts\AuditLogger;
use Core\Identity\Contracts\ApiKeyScopes;
use Core\Identity\Contracts\IssueApiKey as IssueApiKeyPort;
use Core\Identity\Contracts\IssueApiKeyCommand;
use Core\Identity\Contracts\IssuedApiKeyView;
use Core\Identity\Domain\Exception\ApiKeyLimitReached;
use Core\Identity\Domain\Exception\ApiKeyNotFound;
use Core\Identity\Domain\Exception\InvalidApiKeySettings;
use Core\Identity\Domain\Exception\ProjectNotActive;
use Core\Identity\Domain\Exception\ProjectNotFound;
use Core\Identity\Domain\Model\ApiKey;
use Core\Identity\Domain\Model\ApiKeyToken;
use Core\Identity\Domain\Port\ApiKeyRepository;
use Core\Identity\Domain\Port\ApiKeySecretGenerator;
use Core\Identity\Domain\Port\ProjectRepository;
use Core\Support\Clock;
use Core\Support\Identifier;
use Core\Support\UnitOfWork;

/**
 * The single place an API key is issued — CLI (`identity:issue-api-key`),
 * console, project creation and rotation all go through here (ADR-0082).
 */
final readonly class IssueApiKey implements IssueApiKeyPort
{
    public function __construct(
        private ProjectRepository $projects,
        private ApiKeyRepository $apiKeys,
        private ApiKeySecretGenerator $secrets,
        private Identifier $identifier,
        private AuditLogger $audit,
        private Clock $clock,
        private UnitOfWork $unitOfWork,
        private int $maxKeysPerProject = 10,
    ) {}

    public function issue(IssueApiKeyCommand $command, string $actor, ?string $correlationId): IssuedApiKeyView
    {
        return $this->issueKey($command, $actor, $correlationId, rotating: null, graceDays: null);
    }

    /**
     * @internal shared with `RotateApiKey`: the replacement and the old key's
     *           retirement are written in one transaction.
     */
    public function issueKey(
        IssueApiKeyCommand $command,
        string $actor,
        ?string $correlationId,
        ?ApiKey $rotating,
        ?int $graceDays,
    ): IssuedApiKeyView {
        $project = $this->projects->find($command->projectId)
            ?? throw ProjectNotFound::withId($command->projectId);

        if (! $project->isActive()) {
            throw ProjectNotActive::withId($project->id);
        }

        $unknown = ApiKeyScopes::unknown($command->scopes);
        if ($unknown !== []) {
            throw InvalidApiKeySettings::unknownScopes($unknown);
        }

        $generated = $this->secrets->generate();
        $now = $this->clock->now();

        $apiKey = ApiKey::issue(
            id: $this->identifier->generate(),
            projectId: $project->id,
            prefix: $generated->prefix,
            secretHash: ApiKey::hash($generated->secret),
            scopes: $command->scopes,
            issuedAt: $now,
            name: $command->name,
            allowedCidrs: $command->allowedCidrs,
            expiresAt: $command->expiresAt,
        );

        $this->unitOfWork->transactional(function () use ($apiKey, $rotating, $graceDays, $now, $actor, $correlationId): void {
            $this->apiKeys->lockProject($apiKey->projectId);

            // Every key that still authenticates counts, replaced ones in
            // their grace period included. A rotation may take the project
            // one key over the limit (the old key retires on its own), never
            // more: chained rotations cannot pile up valid credentials.
            $usable = $this->apiKeys->countActiveByProject($apiKey->projectId, $now);
            $ceiling = $rotating === null ? $this->maxKeysPerProject : $this->maxKeysPerProject + 1;
            if ($usable >= $ceiling) {
                throw ApiKeyLimitReached::for($this->maxKeysPerProject);
            }

            if ($rotating !== null) {
                // Re-read under the lock: a concurrent rotation of the same
                // key must see the first one's mark and fail.
                $rotating = $this->apiKeys->find($rotating->id) ?? throw ApiKeyNotFound::withId($rotating->id);
                $rotating->markReplacedBy($apiKey->id, $now->modify('+'.($graceDays ?? 0).' days'));
            }

            $this->apiKeys->save($apiKey);
            if ($rotating !== null) {
                $this->apiKeys->save($rotating);
            }

            $this->audit->record(new AuditEntry(
                actor: $actor,
                action: $rotating === null ? 'api_key.issued' : 'api_key.rotated',
                resource: 'api_key:'.$apiKey->id,
                before: $rotating === null ? null : ['replaces' => $rotating->prefix, 'replaced_key_expires_at' => $rotating->expiresAt()?->format(DATE_ATOM)],
                // Never the secret or its hash — only what identifies the credential.
                after: [
                    'project_id' => $apiKey->projectId,
                    'prefix' => $apiKey->prefix,
                    'name' => $apiKey->name,
                    'scopes' => $apiKey->scopes(),
                    'allowed_cidrs' => $apiKey->allowedCidrs(),
                    'expires_at' => $apiKey->expiresAt()?->format(DATE_ATOM),
                ],
                context: [],
                correlationId: $correlationId,
                occurredAt: $now,
                projectId: $apiKey->projectId,
            ));
        });

        return new IssuedApiKeyView(
            apiKeyId: $apiKey->id,
            prefix: $apiKey->prefix,
            plaintextToken: ApiKeyToken::compose($generated->prefix, $generated->secret)->value(),
        );
    }
}
