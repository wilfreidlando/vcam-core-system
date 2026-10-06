<?php

declare(strict_types=1);

namespace Core\Payment\Contracts;

/**
 * ADR-0059 — row counts per status, exposed as Prometheus gauges on
 * `/metrics`. Read-only, no per-Project breakdown (cardinality).
 */
interface PaymentStats
{
    /**
     * @param int|null $createdWithinSeconds only rows created in this window; null = all rows
     * @return array<string, int> status => count
     */
    public function countsByStatus(?int $createdWithinSeconds = null): array;
}
