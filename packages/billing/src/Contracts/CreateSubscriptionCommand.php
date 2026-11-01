<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

/**
 * ADR-0072 §7 — `POST /v1/subscriptions`: the contract a customer signs
 * with a plan.
 *
 * `trialDays` overrides the trial of the plan version; `startAt` (an
 * ISO-8601 timestamp) sets the anchor the periods are computed from
 * (ADR-0071 §4).
 *
 * `idempotencyKey` is the value of the `Idempotency-Key` header the
 * route already requires (G7) — one key for the HTTP protection and the
 * Core's own.
 */
final readonly class CreateSubscriptionCommand
{
    /**
     * @param string $actor "operator:<uuid>", "project:<uuid>" or "service:<name>" — who asked for the write
     */
    public function __construct(
        public string $projectId,
        public string $customerRef,
        public string $planCode,
        public ?int $trialDays = null,
        public ?string $startAt = null,
        public string $idempotencyKey = '',
        public string $actor = '',
        public ?string $correlationId = null,
    ) {}
}
