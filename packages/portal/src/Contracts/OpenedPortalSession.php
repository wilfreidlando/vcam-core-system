<?php

declare(strict_types=1);

namespace Core\Portal\Contracts;

/**
 * ADR-0075 §1 — what an opened session answers: the **one-time link**
 * and the instant it stops working.
 *
 * The link is a secret (ADR-0083 §4): it is worth exactly one use, in
 * the window the module gives it. Whoever holds it renders it once, at
 * the creation, and never writes it down (ADR-0081 S-07).
 */
final readonly class OpenedPortalSession
{
    public function __construct(
        public string $url,
        public string $expiresAt,
        public bool $customerCreated,
        public bool $subscriptionCreated,
    ) {}
}
