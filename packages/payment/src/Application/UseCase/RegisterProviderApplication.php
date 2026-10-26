<?php

declare(strict_types=1);

namespace Core\Payment\Application\UseCase;

use Core\Identity\Contracts\GetProject;
use Core\Identity\Contracts\GetProjectCommand;
use Core\Payment\Contracts\RegisterProviderApplication as RegisterProviderApplicationPort;
use Core\Payment\Contracts\RegisterProviderApplicationCommand;
use Core\Payment\Domain\Exception\ProviderApplicationNotFound;
use Core\Payment\Domain\Exception\ProviderApplicationPublicKeyAlreadyUsed;
use Core\Payment\Domain\Model\ProviderApplication;
use Core\Payment\Domain\Port\ProviderApplicationRepository;
use Core\Support\Access\AccessScope;
use InvalidArgumentException;
use RuntimeException;

/**
 * ADR-0066 — create or edit the (project, provider)
 * application. Every failure is an operator-readable domain exception —
 * never a raw SQL message (the "duplicate key" bug).
 */
final readonly class RegisterProviderApplication implements RegisterProviderApplicationPort
{
    public function __construct(
        private ProviderApplicationRepository $applications,
        private GetProject $getProject,
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

        $owner = $this->applications->findByProviderAndPublicKey($command->providerCode, $publicKey);
        if ($owner !== null && $owner->projectId !== $command->projectId) {
            throw new ProviderApplicationPublicKeyAlreadyUsed($owner->projectId, $this->alreadyUsedMessage($owner, $scope));
        }

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
     * The owner project is named only when the caller may see it (ADR-0069
     * §2, S-01): naming — or even identifying — another tenant's project
     * in an error message would leak it across organizations.
     */
    private function alreadyUsedMessage(ProviderApplication $owner, AccessScope $scope): string
    {
        try {
            $projectName = $this->getProject->get(new GetProjectCommand($owner->projectId), $scope)->name;
        } catch (RuntimeException) {
            return sprintf(
                'Cette clé publique est déjà utilisée par un autre projet. Une application %s ne peut servir '
                .'qu\'un seul projet : c\'est elle qui permet au Core d\'attribuer chaque '
                .'callback au bon projet. Créez une application distincte chez le provider.',
                $owner->providerCode,
            );
        }

        return sprintf(
            'Cette clé publique est déjà utilisée par le projet « %s ». Une application %s ne peut servir '
            .'qu\'un seul projet : c\'est elle qui permet au Core d\'attribuer chaque '
            .'callback au bon projet. Créez une application distincte chez le provider, ou modifiez celle du projet « %s ».',
            $projectName,
            $owner->providerCode,
            $projectName,
        );
    }
}
