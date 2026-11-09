<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

/**
 * ADR-0117 §4 — the purchase of a supplement, as the caller declares it.
 *
 * The **meter** names the metered feature the supplement extends: it is
 * the meter's name of the project (ADR-0071 §3), and the Core refuses a
 * meter the plan of the contract does not carry.
 *
 * The **quantity** is the number of units added to the limit, and the
 * **unit price** is what one of them costs, in minor units of the
 * currency of the contract (G5). The Core computes the total; it never
 * invents a price.
 *
 * The **idempotency key** is the key of the gesture (ADR-0119 §6): a
 * replay with the same key buys once. The HTTP middleware (G7,
 * ADR-0025) absorbs the replay of the *request*; this key absorbs the
 * replay of the *purchase* — the caller that does not go through HTTP
 * (the portal, the console) still buys once.
 */
final readonly class PurchaseSubscriptionSupplementCommand
{
    /**
     * @param string $meter the metered feature the supplement extends
     * @param int $quantity the units added to the limit — at least one
     * @param int $unitPriceMinor the price of one unit, in minor units
     * @param string|null $description the wording of the invoice line
     * @param string $idempotencyKey the key of the gesture — a replay of the same key buys once (ADR-0119 §6)
     * @param string $actor "operator:<uuid>", "project:<uuid>" or "service:<name>" — who asked for the write
     */
    public function __construct(
        public string $projectId,
        public string $subscriptionId,
        public string $meter,
        public int $quantity,
        public int $unitPriceMinor,
        public ?string $description = null,
        public string $actor = 'project:unknown',
        public ?string $correlationId = null,
        public string $idempotencyKey = '',
    ) {}
}
