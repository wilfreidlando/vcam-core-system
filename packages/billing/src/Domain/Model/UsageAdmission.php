<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Model;

/**
 * ADR-0074 §2 — the outcome of one atomic admission.
 *
 * A refusal wrote **nothing**: not the journal, not the counter, not the
 * idempotency key — the call can be replayed later with the same key.
 */
final readonly class UsageAdmission
{
    public const ADMITTED = 'admitted';

    public const REPLAYED = 'replayed';

    public const REFUSED_LIMIT = 'refused_limit';

    public const REFUSED_UNDERFLOW = 'refused_underflow';

    public const REFUSED_CONFLICT = 'refused_conflict';

    private function __construct(
        public string $outcome,
        public int $used,
        public ?int $limit,
        public bool $thresholdCrossed,
        public bool $limitReached,
    ) {}

    public static function admitted(int $used, ?int $limit, bool $thresholdCrossed, bool $limitReached): self
    {
        return new self(self::ADMITTED, $used, $limit, $thresholdCrossed, $limitReached);
    }

    public static function replayed(int $used, ?int $limit): self
    {
        return new self(self::REPLAYED, $used, $limit, false, false);
    }

    public static function refused(string $outcome, int $used, ?int $limit): self
    {
        return new self($outcome, $used, $limit, false, false);
    }

    public function isRefused(): bool
    {
        return in_array($this->outcome, [self::REFUSED_LIMIT, self::REFUSED_UNDERFLOW, self::REFUSED_CONFLICT], true);
    }
}
