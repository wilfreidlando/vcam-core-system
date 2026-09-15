<?php

declare(strict_types=1);

namespace Core\Payment\Contracts;

use InvalidArgumentException;

/**
 * The payment method a customer chose (`mtn_momo`, `orange_money`,
 * `card`…) — the canonical, public half of ADR-0038's split. Distinct
 * from `ProviderCode` (`Core\Support\Integration\Provider\ProviderCode`),
 * which names the adapter class handling the call and never crosses this
 * module's `Contracts/` boundary.
 *
 * An open, validated-string value object rather than a closed enum
 * (mirrors `Core\Ledger\Domain\Model\AccountKind`): the set of channels
 * grows as new rails are integrated (a new MNO, a card network), and that
 * must never require a Core code change. Contrast with `PaymentStatus`,
 * a genuinely closed vocabulary — channels are a catalogue, not a
 * lifecycle.
 */
final readonly class PaymentChannel
{
    private const PATTERN = '/^[a-z][a-z0-9_]*$/';

    private function __construct(
        public string $value,
    ) {}

    public static function fromString(string $value): self
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw new InvalidArgumentException(
                "Invalid payment channel '{$value}': must be lowercase, underscore-separated, starting with a letter."
            );
        }

        return new self($value);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
