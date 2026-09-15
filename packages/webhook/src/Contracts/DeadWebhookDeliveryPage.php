<?php

declare(strict_types=1);

namespace Core\Webhook\Contracts;

final readonly class DeadWebhookDeliveryPage
{
    /**
     * @param list<DeadWebhookDeliveryView> $items
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $page,
        public int $perPage,
    ) {}
}
