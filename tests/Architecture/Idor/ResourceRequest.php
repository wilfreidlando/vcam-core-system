<?php

declare(strict_types=1);

namespace Tests\Architecture\Idor;

/**
 * How to reach one fabricated resource: the path and query parameters, and
 * the markers — values of the owning tenant's data that would betray a
 * leak if they showed up in a response for another tenant.
 */
final readonly class ResourceRequest
{
    /**
     * @param array<string, string> $parameters path parameters, under every name a route may give them
     * @param array<string, string> $query
     * @param list<string> $markers
     */
    public function __construct(
        public array $parameters,
        public array $query = [],
        public array $markers = [],
    ) {}
}
