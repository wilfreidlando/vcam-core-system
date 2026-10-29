<?php

declare(strict_types=1);

namespace Core\Notification\Infrastructure\Quota;

use Core\Billing\Contracts\ConsumeCapabilityUsage;
use Core\Billing\Contracts\ConsumeCapabilityUsageCommand;
use Core\Billing\Contracts\UsageDecision;
use Core\Notification\Domain\Model\QuotaVerdict;
use Core\Notification\Domain\Port\QuotaGuard;

/**
 * ADR-0074 §5 — the adapter of the quota port to `billing`'s contract.
 *
 * The idempotency key is `notification:<key of the notification>`: a
 * replay of the same notification counts once. The refund has its own key.
 */
final readonly class BillingQuotaGuard implements QuotaGuard
{
    public function __construct(private ConsumeCapabilityUsage $usage) {}

    public function consume(string $projectId, ?string $customerRef, string $capability, string $idempotencyKey): QuotaVerdict
    {
        $decision = $this->usage->consume(new ConsumeCapabilityUsageCommand(
            projectId: $projectId,
            customerRef: $customerRef,
            capability: $capability,
            quantity: 1,
            idempotencyKey: 'notification:'.$this->key($idempotencyKey),
            source: 'notification',
        ));

        if ($decision->isAllowed()) {
            return QuotaVerdict::allowed();
        }

        return match ($decision->status) {
            UsageDecision::QUOTA_EXCEEDED => QuotaVerdict::refused(
                QuotaVerdict::EXCEEDED,
                sprintf('The plan allows %s message(s) in this window; %d already used.', $decision->limit ?? 0, $decision->used),
            ),
            UsageDecision::ENTITLEMENT_INACTIVE, UsageDecision::FEATURE_NOT_INCLUDED => QuotaVerdict::refused(
                QuotaVerdict::NOT_ENTITLED,
                'The customer has no active plan that includes this capability.',
            ),
            default => QuotaVerdict::refused(
                QuotaVerdict::INVALID_CUSTOMER,
                'This project meters its notifications: variables.customer_ref must name one of its customers.',
            ),
        };
    }

    public function release(string $projectId, ?string $customerRef, string $capability, string $idempotencyKey): void
    {
        $this->usage->consume(new ConsumeCapabilityUsageCommand(
            projectId: $projectId,
            customerRef: $customerRef,
            capability: $capability,
            quantity: -1,
            idempotencyKey: 'notification-refund:'.$this->key($idempotencyKey),
            source: 'notification',
            compensation: true,
        ));
    }

    /**
     * The caller's own reference is free text up to 255 characters: kept
     * as is when short (readable in the journal), hashed when long so the
     * key always fits the column. The two prefixes cannot collide.
     */
    private function key(string $reference): string
    {
        return strlen($reference) <= 150 ? $reference : 'h:'.hash('sha256', $reference);
    }
}
