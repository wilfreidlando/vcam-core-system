<?php

declare(strict_types=1);

namespace Core\Payment\Application\UseCase;

use Core\Payment\Contracts\ListProviderApplications;
use Core\Payment\Contracts\ListProviderApplicationsCommand;
use Core\Payment\Contracts\ProviderApplicationView;
use Core\Payment\Domain\Model\ProviderApplication;
use Core\Payment\Domain\Port\ProviderApplicationRepository;
use Core\Support\Access\AccessScope;

final readonly class ListProviderApplicationsUseCase implements ListProviderApplications
{
    public function __construct(
        private ProviderApplicationRepository $applications,
    ) {}

    public function list(ListProviderApplicationsCommand $command, AccessScope $scope): array
    {
        // ADR-0069 §2 — a project outside the account's scope has no
        // application to show.
        if (! $scope->allowsProject($command->projectId)) {
            return [];
        }

        $applications = $this->applications->findAllByProject($command->projectId);

        return array_map(
            static fn (ProviderApplication $application): ProviderApplicationView => new ProviderApplicationView(
                providerCode: $application->providerCode,
                publicKey: $application->publicKey,
                id: $application->id ?? '',
                label: $application->label,
                maskedPublicKey: $application->maskedPublicKey(),
                createdAt: $application->createdAt?->format(DATE_ATOM),
                updatedAt: $application->updatedAt?->format(DATE_ATOM),
            ),
            $applications,
        );
    }
}
