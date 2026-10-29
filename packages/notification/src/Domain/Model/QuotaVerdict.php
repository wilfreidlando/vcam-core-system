<?php

declare(strict_types=1);

namespace Core\Notification\Domain\Model;

/**
 * ADR-0074 §5 — the answer of the quota authority to one notification.
 *
 * Plain scalars on purpose (G3b): `notification` never sees a type of
 * `billing`.
 */
final readonly class QuotaVerdict
{
    public const ALLOWED = 'allowed';

    public const EXCEEDED = 'exceeded';

    public const NOT_ENTITLED = 'not_entitled';

    public const INVALID_CUSTOMER = 'invalid_customer';

    private function __construct(
        public string $outcome,
        public ?string $detail = null,
    ) {}

    public static function allowed(): self
    {
        return new self(self::ALLOWED);
    }

    public static function refused(string $outcome, string $detail): self
    {
        return new self($outcome, $detail);
    }

    public function isAllowed(): bool
    {
        return $this->outcome === self::ALLOWED;
    }
}
