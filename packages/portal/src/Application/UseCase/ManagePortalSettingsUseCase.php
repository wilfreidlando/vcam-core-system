<?php

declare(strict_types=1);

namespace Core\Portal\Application\UseCase;

use Core\Portal\Application\Dto\PortalSettingsData;
use Core\Portal\Contracts\ManagePortalSettings;
use Core\Portal\Contracts\PortalSettingsView;
use Core\Portal\Contracts\SavePortalSettingsCommand;
use Core\Support\Access\AccessScope;

final readonly class ManagePortalSettingsUseCase implements ManagePortalSettings
{
    public function __construct(private PortalSettingsService $settings) {}

    public function get(string $projectId, AccessScope $scope): ?PortalSettingsView
    {
        return $scope->allowsProject($projectId) ? $this->view($this->settings->get($projectId)) : null;
    }

    public function save(SavePortalSettingsCommand $command, AccessScope $scope): ?PortalSettingsView
    {
        if (! $scope->allowsProject($command->projectId)) {
            return null;
        }

        return $this->view($this->settings->save($command->projectId, new PortalSettingsData(
            $command->displayName,
            $command->logoUrl,
            $command->primaryColor,
            $command->supportUrl,
            $command->locale,
            $command->allowedReturnUrls,
            $command->allowSelfCancel,
            $command->allowPlanChange,
            $command->paymentChannels,
            $command->directLoginEnabled,
            $command->directLoginSession,
        ), $command->correlationId, $command->actor));
    }

    private function view(PortalSettingsData $d): PortalSettingsView
    {
        return new PortalSettingsView(
            $d->displayName,
            $d->logoUrl,
            $d->primaryColor,
            $d->supportUrl,
            $d->locale,
            $d->allowedReturnUrls,
            $d->allowSelfCancel,
            $d->allowPlanChange,
            $d->paymentChannels,
            $d->directLoginEnabled,
            $d->directLoginSession,
        );
    }
}
