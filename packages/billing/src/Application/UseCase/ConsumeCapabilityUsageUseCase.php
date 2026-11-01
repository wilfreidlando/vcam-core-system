<?php

declare(strict_types=1);

namespace Core\Billing\Application\UseCase;

use Core\Billing\Contracts\ConsumeCapabilityUsage;
use Core\Billing\Contracts\ConsumeCapabilityUsageCommand;
use Core\Billing\Contracts\ConsumeUsage;
use Core\Billing\Contracts\ConsumeUsageCommand;
use Core\Billing\Contracts\UsageDecision;
use Core\Billing\Domain\Port\BillingSettingsRepository;

/**
 * ADR-0074 §5 — resolves the meter a capability feeds, then spends.
 */
final readonly class ConsumeCapabilityUsageUseCase implements ConsumeCapabilityUsage
{
    public function __construct(
        private BillingSettingsRepository $settings,
        private ConsumeUsage $consumeUsage,
    ) {}

    public function consume(ConsumeCapabilityUsageCommand $command): UsageDecision
    {
        $settings = $this->settings->find($command->projectId);
        $meter = $settings->meterFor($command->capability);

        // No mapping, or a project that does not give failed units back:
        // nothing to count.
        if ($meter === null || ($command->compensation && ! $settings->refundFailedUsage)) {
            return new UsageDecision(UsageDecision::NOT_METERED, $meter ?? '', 0, null, null, null);
        }

        return $this->consumeUsage->consume(new ConsumeUsageCommand(
            projectId: $command->projectId,
            customerRef: $command->customerRef,
            meter: $meter,
            quantity: $command->quantity,
            idempotencyKey: $command->idempotencyKey,
            source: $command->source,
            // The Core's own effects are enforced; a compensation is a
            // release and cannot be refused for being "over" a limit.
            mode: 'enforce',
            correlationId: $command->correlationId,
        ));
    }
}
