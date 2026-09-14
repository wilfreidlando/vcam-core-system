<?php

declare(strict_types=1);

namespace Core\Payment\Tests\Unit\Domain\Model;

use Core\Payment\PaymentStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PaymentStatusTest extends TestCase
{
    #[Test]
    public function it_exposes_seven_canonical_cases(): void
    {
        $values = array_map(
            static fn (PaymentStatus $case): string => $case->value,
            PaymentStatus::cases(),
        );

        sort($values);
        self::assertSame(
            ['canceled', 'expired', 'failed', 'pending', 'processing', 'refunded', 'succeeded'],
            $values,
        );
    }

    #[Test]
    public function succeeded_failed_expired_refunded_canceled_are_terminal(): void
    {
        self::assertTrue(PaymentStatus::Succeeded->isTerminal());
        self::assertTrue(PaymentStatus::Failed->isTerminal());
        self::assertTrue(PaymentStatus::Expired->isTerminal());
        self::assertTrue(PaymentStatus::Refunded->isTerminal());
        self::assertTrue(PaymentStatus::Canceled->isTerminal());
    }

    #[Test]
    public function pending_and_processing_are_not_terminal(): void
    {
        self::assertFalse(PaymentStatus::Pending->isTerminal());
        self::assertFalse(PaymentStatus::Processing->isTerminal());
    }

    #[Test]
    public function value_round_trips_through_backed_enum(): void
    {
        foreach (PaymentStatus::cases() as $case) {
            self::assertSame($case, PaymentStatus::from($case->value));
        }
    }
}
