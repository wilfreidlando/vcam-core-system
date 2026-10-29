<?php

declare(strict_types=1);

namespace Core\Support\Tests\Unit\Money;

use Core\Support\Money\Money;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    #[Test]
    public function the_grouped_amount_separates_thousands_for_a_human_and_keeps_the_decimals(): void
    {
        self::assertSame('85 000', Money::ofMinor(85_000, 'XAF')->groupedAmount());
        self::assertSame('999', Money::ofMinor(999, 'XAF')->groupedAmount());
        self::assertSame('1 234 567', Money::ofMinor(1_234_567, 'XAF')->groupedAmount());
        self::assertSame('-20 000', Money::ofMinor(-20_000, 'XAF')->groupedAmount());
        self::assertSame('1 234.50', Money::ofMinor(123_450, 'EUR')->groupedAmount());
        self::assertSame('0', Money::ofMinor(0, 'XAF')->groupedAmount());
    }

    #[Test]
    public function formatted_amount_has_no_decimals_for_a_zero_exponent_currency(): void
    {
        self::assertSame('100', Money::ofMinor(100, 'XAF')->formattedAmount());
        self::assertSame('150000', Money::ofMinor(150_000, 'XAF')->formattedAmount());
    }

    #[Test]
    public function formatted_amount_has_two_decimals_for_a_standard_currency(): void
    {
        self::assertSame('1.00', Money::ofMinor(100, 'EUR')->formattedAmount());
        self::assertSame('1500.55', Money::ofMinor(150_055, 'EUR')->formattedAmount());
    }
}
