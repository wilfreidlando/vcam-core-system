<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

/**
 * ADR-0117 §2 — the supplements of one contract.
 *
 * The listing is bounded by the contract: it is the history of the
 * purchases that hang on it, not a project-wide listing, so it carries
 * no cursor (ADR-0088 §2 applies to the project-wide listings).
 */
final readonly class ListSubscriptionSupplementsCommand
{
    public function __construct(
        public string $projectId,
        public string $subscriptionId,
    ) {}
}
