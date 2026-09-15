<?php

declare(strict_types=1);

namespace Core\Support\Money;

use Brick\Math\Exception\MathException;
use Brick\Money\Money as BrickMoney;
use InvalidArgumentException;

/**
 * The only monetary type the Core hands across a module boundary
 * (ADR-0021). `Brick\Money\Money` never crosses a `Contracts/` surface —
 * brick/money is a 0.x dependency with breaking minor releases, and this is
 * the single file that would need to change if it were ever replaced.
 *
 * Always an exact integer count of minor units (ADR-0004): never float,
 * never a fractional minor unit. Two amounts in different currencies can
 * never be added — that check is the entire point of wrapping a raw bigint.
 */
final readonly class Money
{
    private function __construct(
        private BrickMoney $money,
    ) {}

    public static function ofMinor(int $amountMinor, string $currency): self
    {
        try {
            return new self(BrickMoney::ofMinor($amountMinor, $currency));
        } catch (MathException $e) {
            throw new InvalidArgumentException("Invalid money amount: {$e->getMessage()}", previous: $e);
        }
    }

    public static function zero(string $currency): self
    {
        return new self(BrickMoney::zero($currency));
    }

    public function amountMinor(): int
    {
        return $this->money->getMinorAmount()->toInt();
    }

    public function currency(): string
    {
        return $this->money->getCurrency()->getCurrencyCode();
    }

    public function plus(self $other): self
    {
        return new self($this->money->plus($this->sameCurrency($other)->money));
    }

    public function minus(self $other): self
    {
        return new self($this->money->minus($this->sameCurrency($other)->money));
    }

    public function negated(): self
    {
        return new self($this->money->negated());
    }

    public function isZero(): bool
    {
        return $this->money->isZero();
    }

    public function isPositive(): bool
    {
        return $this->money->isPositive();
    }

    public function isNegative(): bool
    {
        return $this->money->isNegative();
    }

    public function isGreaterThan(self $other): bool
    {
        return $this->money->isGreaterThan($this->sameCurrency($other)->money);
    }

    public function isGreaterThanOrEqualTo(self $other): bool
    {
        return $this->money->isGreaterThanOrEqualTo($this->sameCurrency($other)->money);
    }

    public function isLessThan(self $other): bool
    {
        return $this->money->isLessThan($this->sameCurrency($other)->money);
    }

    public function equals(self $other): bool
    {
        return $this->money->isSameValueAs($other->money);
    }

    public function __toString(): string
    {
        return $this->money->__toString();
    }

    private function sameCurrency(self $other): self
    {
        if ($this->currency() !== $other->currency()) {
            throw new InvalidArgumentException(
                "Cannot operate on {$this->currency()} and {$other->currency()} together — ".
                'convert explicitly first (spec technique §7: brick/money forbids cross-currency arithmetic).'
            );
        }

        return $other;
    }
}
