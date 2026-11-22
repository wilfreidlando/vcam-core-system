<?php

declare(strict_types=1);

namespace Core\Payment\Application\UseCase;

use Core\Identity\Contracts\GetProject;
use Core\Identity\Contracts\GetProjectCommand;
use Core\Payment\Contracts\RegisterProviderApplication as RegisterProviderApplicationPort;
use Core\Payment\Contracts\RegisterProviderApplicationCommand;
use Core\Payment\Domain\Exception\ProviderApplicationNotFound;
use Core\Payment\Domain\Model\ProviderApplication;
use Core\Payment\Domain\Port\ProviderApplicationRepository;
use Core\Support\Access\AccessScope;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;

/**
 * ADR-0066 — create or edit the (project, provider)
 * application. Every failure is an operator-readable domain exception —
 * never a raw SQL message (the "duplicate key" bug).
 *
 * ADR-0126 — the public key is no longer unique across projects: the
 * same key pair may serve several projects. It is therefore not a
 * refusal any more, but a **shared** key, and the operator is warned
 * about it: a callback is then authenticated by a key several tenants
 * hold.
 */
final readonly class RegisterProviderApplication implements RegisterProviderApplicationPort
{
    public function __construct(
        private ProviderApplicationRepository $applications,
        private GetProject $getProject,
        private LoggerInterface $logger,
    ) {}

    public function register(RegisterProviderApplicationCommand $command, AccessScope $scope): bool
    {
        // Validates the Project exists **and** is in the caller's scope
        // (ADR-0069 §2) — `GetProject` answers "not found" for both, with a
        // RuntimeException subtype (Contracts must not reference the
        // concrete Identity exception type, G3c). The scope is the
        // caller's, never one fabricated here: a use case that built its
        // own scope would check nothing.
        $this->getProject->get(new GetProjectCommand($command->projectId), $scope);

        $publicKey = trim($command->publicKey);
        if ($publicKey === '') {
            throw new InvalidArgumentException('La clé publique est obligatoire.');
        }

        $existing = $this->applications->findByProjectAndProvider($command->projectId, $command->providerCode);

        $privateKey = $command->privateKey !== null && trim($command->privateKey) !== ''
            ? trim($command->privateKey)
            : $existing?->privateKey;

        if ($privateKey === null) {
            throw new InvalidArgumentException('La clé privée est obligatoire pour une nouvelle application.');
        }

        $this->warnWhenKeyIsShared($command->providerCode, $publicKey, $command->projectId);

        $this->applications->save(new ProviderApplication(
            projectId: $command->projectId,
            providerCode: $command->providerCode,
            publicKey: $publicKey,
            privateKey: $privateKey,
            id: $existing?->id,
            label: $command->label ?? $existing?->label,
        ));

        return $existing !== null;
    }

    public function delete(string $projectId, string $applicationId, AccessScope $scope): void
    {
        // ADR-0069 §2 — same rule as register(): the project must be in
        // the caller's scope.
        if (! $scope->allowsProject($projectId)) {
            throw ProviderApplicationNotFound::withId($applicationId);
        }

        $application = $this->applications->find($applicationId);
        if ($application === null || $application->projectId !== $projectId) {
            throw ProviderApplicationNotFound::withId($applicationId);
        }

        $this->applications->delete($applicationId);
    }

    /**
     * A public key shared by several projects is legitimate (ADR-0126) —
     * but it is also what makes an inbound callback ambiguous, so the
     * operator is told about it in the log. Only the count is logged:
     * naming another tenant's project would leak it (ADR-0069 §2, S-01).
     */
    private function warnWhenKeyIsShared(string $providerCode, string $publicKey, string $projectId): void
    {
        $others = 0;
        foreach ($this->applications->findAllByProviderAndPublicKey($providerCode, $publicKey) as $application) {
            if ($application->projectId !== $projectId) {
                $others++;
            }
        }

        if ($others === 0) {
            return;
        }

        $this->logger->warning('payment.provider_application.shared_public_key', [
            'provider_code' => $providerCode,
            'public_key_prefix' => substr($publicKey, 0, 4),
            'other_projects_count' => $others,
        ]);
    }
}
