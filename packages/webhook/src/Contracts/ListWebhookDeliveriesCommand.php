<?php

declare(strict_types=1);

namespace Core\Webhook\Contracts;

/**
 * `status` is a canonical `WebhookDeliveryStatus` value ('pending',
 * 'delivered', 'dead') as a plain string — never the enum itself (G3c) —
 * and nullable: `null` lists every status, an unrecognised value is
 * silently ignored (same convention as `ListPaymentsCommand::$status`).
 * `projectId` is nullable too, same convention as
 * `ListDeadWebhookDeliveriesCommand::$projectId` — the operator console
 * lists across every Project by default.
 */
final readonly class ListWebhookDeliveriesCommand
{
    public function __construct(
        public ?string $status = null,
        public ?string $projectId = null,
        public int $page = 1,
        public int $perPage = 25,
    ) {}
}
