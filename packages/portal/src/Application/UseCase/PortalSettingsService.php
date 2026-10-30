<?php

declare(strict_types=1);

namespace Core\Portal\Application\UseCase;

use Core\Audit\Contracts\AuditEntry;
use Core\Audit\Contracts\AuditLogger;
use Core\Portal\Application\Dto\PortalSettingsData;
use Core\Portal\Domain\Model\PortalSettings;
use Core\Portal\Domain\Port\PortalSettingsRepository;
use Core\Support\Clock;

/**
 * ADR-0075 §4 — the project reads and writes the look and the permissions
 * of its portal. Validation is the domain's; every change is audited.
 */
final readonly class PortalSettingsService
{
    public function __construct(
        private PortalSettingsRepository $settings,
        private AuditLogger $audit,
        private Clock $clock,
    ) {}

    public function get(string $projectId): PortalSettingsData
    {
        // A project that never chose anything reads the defaults: the API
        // answers the same thing whether the settings exist or not.
        return $this->toData($this->settings->find($projectId) ?? PortalSettings::defaultsFor($projectId));
    }

    public function save(string $projectId, PortalSettingsData $data, ?string $correlationId, ?string $actor = null): PortalSettingsData
    {
        $existing = $this->settings->find($projectId) ?? PortalSettings::defaultsFor($projectId);
        $before = $this->toData($existing);

        $validated = PortalSettings::validated(
            $projectId,
            $data->displayName,
            $data->logoUrl,
            $data->primaryColor,
            $data->supportUrl,
            $data->locale,
            $data->allowedReturnUrls,
            $data->allowSelfCancel,
            $data->allowPlanChange,
            $data->paymentChannels,
            $data->directLoginEnabled,
            $data->directLoginSession,
            $data->allowedAssetOrigins ?? $existing->allowedAssetOrigins,
        );

        $this->settings->save($validated);
        $after = $this->toData($validated);

        $this->audit->record(new AuditEntry(
            actor: $actor ?? 'project:'.$projectId,
            action: 'portal.settings_changed',
            resource: 'project:'.$projectId,
            before: (array) $before,
            after: (array) $after,
            context: [],
            correlationId: $correlationId,
            occurredAt: $this->clock->now(),
            projectId: $projectId,
        ));

        return $after;
    }

    private function toData(PortalSettings $s): PortalSettingsData
    {
        return new PortalSettingsData(
            $s->displayName,
            $s->logoUrl,
            $s->primaryColor,
            $s->supportUrl,
            $s->locale,
            $s->allowedReturnUrls,
            $s->allowSelfCancel,
            $s->allowPlanChange,
            $s->paymentChannels,
            $s->directLoginEnabled,
            $s->directLoginSession,
            $s->allowedAssetOrigins,
        );
    }
}
