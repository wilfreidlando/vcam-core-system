<?php

declare(strict_types=1);

namespace Core\Identity\Application\UseCase;

use Core\Audit\Contracts\AuditEntry;
use Core\Audit\Contracts\AuditLogger;
use Core\Identity\Application\Dto\IssueApiKeyCommand;
use Core\Identity\Application\Dto\IssuedApiKey;
use Core\Identity\Domain\Exception\ProjectNotActive;
use Core\Identity\Domain\Exception\ProjectNotFound;
use Core\Identity\Domain\Model\ApiKey;
use Core\Identity\Domain\Model\ApiKeyToken;
use Core\Identity\Domain\Port\ApiKeyRepository;
use Core\Identity\Domain\Port\ApiKeySecretGenerator;
use Core\Identity\Domain\Port\ProjectRepository;
use Core\Support\Clock;
use Core\Support\Identifier;

final readonly class IssueApiKey
{
    public function __construct(
        private ProjectRepository $projects,
        private ApiKeyRepository $apiKeys,
        private ApiKeySecretGenerator $secrets,
        private Identifier $identifier,
        private AuditLogger $audit,
        private Clock $clock,
    ) {}

    public function __invoke(IssueApiKeyCommand $command, string $actor, ?string $correlationId): IssuedApiKey
    {
        $project = $this->projects->find($command->projectId)
            ?? throw ProjectNotFound::withId($command->projectId);

        if (! $project->isActive()) {
            throw ProjectNotActive::withId($project->id);
        }

        $generated = $this->secrets->generate($command->environment);
        $now = $this->clock->now();

        $apiKey = ApiKey::issue(
            id: $this->identifier->generate(),
            projectId: $project->id,
            environment: $command->environment,
            prefix: $generated->prefix,
            secretHash: ApiKey::hash($generated->secret),
            scopes: $command->scopes,
            issuedAt: $now,
        );

        $this->apiKeys->save($apiKey);

        $this->audit->record(new AuditEntry(
            actor: $actor,
            action: 'api_key.issued',
            resource: 'api_key:'.$apiKey->id,
            before: null,
            // Never the secret or its hash — only what identifies the credential.
            after: ['project_id' => $project->id, 'environment' => $command->environment->value, 'prefix' => $apiKey->prefix, 'scopes' => $command->scopes],
            context: [],
            correlationId: $correlationId,
            occurredAt: $now,
        ));

        return new IssuedApiKey(
            apiKeyId: $apiKey->id,
            prefix: $apiKey->prefix,
            plaintextToken: ApiKeyToken::compose($generated->prefix, $generated->secret)->value(),
        );
    }
}
