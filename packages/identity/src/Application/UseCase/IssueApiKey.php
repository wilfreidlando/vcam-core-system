<?php

declare(strict_types=1);

namespace Core\Identity\Application\UseCase;

use Core\Audit\Contracts\AuditEntry;
use Core\Audit\Contracts\AuditLogger;
use Core\Identity\Contracts\IssueApiKey as IssueApiKeyPort;
use Core\Identity\Contracts\IssueApiKeyCommand;
use Core\Identity\Contracts\IssuedApiKeyView;
use Core\Identity\Domain\Exception\ApiKeyAlreadyExists;
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
 * Reachable from both the CLI (`identity:issue-api-key`) and the admin
 * console's self-service endpoint — a single place where an API key is
 * issued, whichever caller triggers it. Mirrors `CreateOperator`/
 * `CreateOrganization` (ADR-0050): a named `issue()` method, not
 * `__invoke()` — this used to be invokable, retyped to the `Contracts`
 * port's convention when self-service was added.
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
    ) {}

    public function issue(IssueApiKeyCommand $command, string $actor, ?string $correlationId): IssuedApiKeyView
    {
        $project = $this->projects->find($command->projectId)
            ?? throw ProjectNotFound::withId($command->projectId);

        if (! $project->isActive()) {
            throw ProjectNotActive::withId($project->id);
        }

        $generated = $this->secrets->generate();
        $now = $this->clock->now();

        // ADR-0066: one key per project. Regenerating deletes the previous
        // key in the same transaction as the insert — there is never a
        // moment with zero or two valid keys.
        $existing = $this->apiKeys->findOneByProject($project->id);
        if ($existing !== null && ! $command->replaceExisting) {
            throw ApiKeyAlreadyExists::for($project->id, $existing->prefix);
        }

        $apiKey = ApiKey::issue(
            id: $this->identifier->generate(),
            projectId: $project->id,
            prefix: $generated->prefix,
            secretHash: ApiKey::hash($generated->secret),
            scopes: $command->scopes,
            issuedAt: $now,
        );

        $this->unitOfWork->transactional(function () use ($existing, $apiKey, $actor, $correlationId, $now): void {
            if ($existing !== null) {
                $this->apiKeys->delete($existing->id);
                $this->audit->record(new AuditEntry(
                    actor: $actor,
                    action: 'api_key.replaced',
                    resource: 'api_key:'.$existing->id,
                    before: ['project_id' => $existing->projectId, 'prefix' => $existing->prefix],
                    after: ['replaced_by' => $apiKey->prefix],
                    context: [],
                    correlationId: $correlationId,
                    occurredAt: $now,
                ));
            }

            $this->apiKeys->save($apiKey);
        });

        $this->audit->record(new AuditEntry(
            actor: $actor,
            action: $existing === null ? 'api_key.issued' : 'api_key.regenerated',
            resource: 'api_key:'.$apiKey->id,
            before: null,
            // Never the secret or its hash — only what identifies the credential.
            after: ['project_id' => $project->id, 'prefix' => $apiKey->prefix, 'scopes' => $command->scopes],
            context: [],
            correlationId: $correlationId,
            occurredAt: $now,
        ));

        return new IssuedApiKeyView(
            apiKeyId: $apiKey->id,
            prefix: $apiKey->prefix,
            plaintextToken: ApiKeyToken::compose($generated->prefix, $generated->secret)->value(),
        );
    }
}
