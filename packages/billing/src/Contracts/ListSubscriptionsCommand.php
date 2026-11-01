<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

/**
 * ADR-0072 §7 — `GET /v1/subscriptions?customer_ref=&status=&cursor=`.
 *
 * `status` is a canonical `SubscriptionStatus` value ('incomplete',
 * 'trialing', 'active', 'past_due', 'paused', 'canceled', 'expired') as a
 * plain string — never the enum itself (G3c) — and nullable: `null`
 * lists every status, an unrecognised value is ignored (same convention
 * as `ListWebhookDeliveriesCommand::$status`).
 */
final readonly class ListSubscriptionsCommand
{
    public function __construct(
        public string $projectId,
        public ?string $customerRef = null,
        public ?string $status = null,
        public ?string $cursor = null,
        public int $limit = 50,
    ) {}
}
