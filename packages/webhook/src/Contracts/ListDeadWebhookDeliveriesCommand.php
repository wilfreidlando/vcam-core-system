<?php

declare(strict_types=1);

namespace Core\Webhook\Contracts;

final readonly class ListDeadWebhookDeliveriesCommand
{
    public function __construct(
        public ?string $projectId = null,
        public int $page = 1,
        public int $perPage = 25,
    ) {}
}
