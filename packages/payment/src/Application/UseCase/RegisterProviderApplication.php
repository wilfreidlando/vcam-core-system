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

    public function register(RegisterProviderApplicationCommand $command): bool
    {
        // Validates the Project exists — throws a RuntimeException subtype
        // on its own if it doesn't (Contracts must not reference the
        // concrete Identity exception type, G3c).
        $this->getProject->get(new GetProjectCommand($command->projectId));

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
            throw new ProviderApplicationPublicKeyAlreadyUsed($owner->projectId, $this->alreadyUsedMessage($owner));
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

    public function delete(string $projectId, string $applicationId): void
    {
        $application = $this->applications->find($applicationId);
        if ($application === null || $application->projectId !== $projectId) {
            throw ProviderApplicationNotFound::withId($applicationId);
        }

        $this->applications->delete($applicationId);
    }

    private function alreadyUsedMessage(ProviderApplication $owner): string
    {
        try {
            $projectName = $this->getProject->get(new GetProjectCommand($owner->projectId))->name;
        } catch (RuntimeException) {
            $projectName = $owner->projectId;
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
