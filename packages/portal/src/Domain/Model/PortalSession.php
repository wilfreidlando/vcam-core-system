<?php

declare(strict_types=1);

namespace Core\Portal\Domain\Model;

use DateTimeImmutable;

/**
 * ADR-0075 §1 — a portal session, in its two lives.
 *
 * It is born as a **one-time link** (a token, valid ten minutes, whose
 * HMAC is all that is stored); the browser exchanges it once for a
 * **cookie session** (idle 30 minutes, absolute 8 hours). The link and the
 * cookie are different secrets: the link given to the customer is worthless
 * once consumed.
 */
final readonly class PortalSession
{
    public const FLOW_MANAGE = 'manage';

    public const FLOW_PAY_INVOICE = 'pay_invoice';

    public const FLOWS = [self::FLOW_MANAGE, self::FLOW_PAY_INVOICE];

    public const TOKEN_TTL_SECONDS = 600;

    public const IDLE_TTL_SECONDS = 1800;

    public const ABSOLUTE_TTL_SECONDS = 28800;

    public function __construct(
        public string $id,
        public string $projectId,
        public string $customerId,
        public string $customerRef,
        public string $flow,
        public ?string $flowRef,
        public ?string $returnUrl,
        public DateTimeImmutable $tokenExpiresAt,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $sessionExpiresAt = null,
    ) {}
}
