<?php

declare(strict_types=1);

namespace Core\Payment\Tests\Unit;

use Core\Payment\PaymentFlow;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PaymentFlowTest extends TestCase
{
    #[Test]
    public function it_exposes_two_flows(): void
    {
        $values = array_map(
            static fn (PaymentFlow $case): string => $case->value,
            PaymentFlow::cases(),
        );

        sort($values);
        self::assertSame(['payin', 'paylink'], $values);
    }

    #[Test]
    public function value_round_trips_through_backed_enum(): void
    {
        foreach (PaymentFlow::cases() as $case) {
            self::assertSame($case, PaymentFlow::from($case->value));
        }
    }
}
