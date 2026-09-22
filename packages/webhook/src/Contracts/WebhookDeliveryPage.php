<?php

declare(strict_types=1);

namespace Core\Webhook\Contracts;

final readonly class WebhookDeliveryPage
{
    /**
     * @param list<WebhookDeliveryView> $items
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $page,
        public int $perPage,
    ) {}
}
