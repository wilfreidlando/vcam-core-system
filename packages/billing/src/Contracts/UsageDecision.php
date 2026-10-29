<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

/**
 * ADR-0074 §2, §3 — the answer to one metered call.
 *
 * A refusal is a **value**, not an exception: another module (the
 * notification cascade) consumes this contract and must not know the
 * exceptions of `billing` (G3c). The status is a closed set of strings.
 */
final readonly class UsageDecision
{
    public const ADMITTED = 'admitted';

    public const REPLAYED = 'replayed';

    public const QUOTA_EXCEEDED = 'quota_exceeded';

    public const UNDERFLOW = 'underflow';

    /** The idempotency key was already used for a different call. */
    public const KEY_CONFLICT = 'key_conflict';

    public const ENTITLEMENT_INACTIVE = 'entitlement_inactive';

    public const FEATURE_NOT_INCLUDED = 'feature_not_included';

    public const CUSTOMER_REF_REQUIRED = 'customer_ref_required';

    public const CUSTOMER_NOT_FOUND = 'customer_not_found';

    /** No meter is mapped to the capability: nothing was counted. */
    public const NOT_METERED = 'not_metered';

    public function __construct(
        public string $status,
        public string $meter,
        public int $used,
        public ?int $limit,
        public ?string $windowStart,
        public ?string $windowEnd,
    ) {}

    /**
     * Did the unit get through — now, or on the call this one replays?
     */
    public function isAllowed(): bool
    {
        return $this->status === self::ADMITTED
            || $this->status === self::REPLAYED
            || $this->status === self::NOT_METERED;
    }

    public function remaining(): ?int
    {
        return $this->limit === null ? null : max(0, $this->limit - $this->used);
    }
}
