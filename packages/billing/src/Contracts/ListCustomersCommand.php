<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

/**
 * ADR-0070 §2 — `GET /v1/customers?cursor=&limit=`: the customers of one
 * project, page after page.
 *
 * `search` filters on the external reference and the display name, the
 * two fields the project knows a customer by; it is matched literally.
 */
final readonly class ListCustomersCommand
{
    public function __construct(
        public string $projectId,
        public ?string $cursor,
        public int $limit = 50,
        public ?string $search = null,
    ) {}
}
