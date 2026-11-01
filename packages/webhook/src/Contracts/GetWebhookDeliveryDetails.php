<?php

declare(strict_types=1);

namespace Core\Webhook\Contracts;

use Core\Support\Access\AccessScope;

interface GetWebhookDeliveryDetails
{
    /**
     * ADR-0069 §2 / S-04 — the scope is a REQUIRED parameter. A delivery
     * belonging to a project outside the account's scope is reported as
     * *not found* (`null`), never as forbidden: the answer must not
     * confirm that the resource exists.
     */
    public function forDelivery(string $deliveryId, AccessScope $scope): ?WebhookDeliveryDetailView;

    /**
     * Every delivery of these outbox events (the fan-out and its replays).
     *
     * ADR-0069 §2 — same rule: the scope is required and the deliveries
     * of a foreign project are left out of the result.
     *
     * @param list<string> $eventIds
     * @return list<WebhookDeliveryDetailView>
     */
    public function forEvents(array $eventIds, AccessScope $scope): array;
}
