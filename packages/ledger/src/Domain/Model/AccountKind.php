<?php

declare(strict_types=1);

namespace Core\Ledger\Domain\Model;

use InvalidArgumentException;

/**
 * An open chart-of-accounts identifier (ADR-0033 §4), not a closed enum:
 * `wallet` today, `platform_fee`/`provider_fee`/`clearing` from later lots —
 * minted by whichever module needs a new kind, without ever touching this
 * module. Only the *shape* is validated, not a fixed vocabulary.
 */
final readonly class AccountKind
{
    private const PATTERN = '/^[a-z][a-z0-9_]*$/';

    private function __construct(
        public string $value,
    ) {}

    public static function fromString(string $value): self
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw new InvalidArgumentException(
                "Invalid account kind '{$value}': must be lowercase, underscore-separated, starting with a letter."
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
