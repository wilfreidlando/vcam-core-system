<?php

declare(strict_types=1);

namespace Core\Payment\Tests\Unit\Infrastructure\Provider\MyCoolPay;

use Core\Payment\Infrastructure\Provider\MyCoolPay\MyCoolPayStatusMapper;
use Core\Payment\Infrastructure\Provider\MyCoolPay\RawMyCoolPayStatus;
use Core\Payment\PaymentStatus;
use Core\Support\Integration\Exception\UnknownProviderStatusException;
use Core\Support\Integration\Provider\ProviderCode;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MyCoolPayStatusMapperTest extends TestCase
{
    private MyCoolPayStatusMapper $mapper;

    #[Test]
    public function success_maps_to_succeeded(): void
    {
        self::assertSame(PaymentStatus::Succeeded, $this->mapper->map(RawMyCoolPayStatus::SUCCESS));
    }

    #[Test]
    public function canceled_maps_to_canceled(): void
    {
        self::assertSame(PaymentStatus::Canceled, $this->mapper->map(RawMyCoolPayStatus::CANCELED));
    }

    #[Test]
    public function failed_maps_to_failed(): void
    {
        self::assertSame(PaymentStatus::Failed, $this->mapper->map(RawMyCoolPayStatus::FAILED));
    }

    #[Test]
    public function pending_maps_to_pending(): void
    {
        self::assertSame(PaymentStatus::Pending, $this->mapper->map(RawMyCoolPayStatus::PENDING));
    }

    #[Test]
    public function require_otp_maps_to_pending(): void
    {
        // The caller surfaces `requiresAction = true` via the receipt.
        // The status itself is still `pending`.
        self::assertSame(PaymentStatus::Pending, $this->mapper->map(RawMyCoolPayStatus::REQUIRE_OTP));
    }

    #[Test]
    public function ussd_ok_maps_to_pending(): void
    {
        self::assertSame(PaymentStatus::Pending, $this->mapper->map(RawMyCoolPayStatus::USSD_OK));
    }

    #[Test]
    public function lowercase_input_is_accepted(): void
    {
        self::assertSame(PaymentStatus::Succeeded, $this->mapper->map('success'));
    }

    #[Test]
    public function unknown_value_throws(): void
    {
        $this->expectException(UnknownProviderStatusException::class);
        $this->mapper->map(RawMyCoolPayStatus::PARTIALLY_PAID);
    }

    protected function setUp(): void
    {
        $this->mapper = new MyCoolPayStatusMapper(
            ProviderCode::fromString('my-coolpay'),
        );
    }
}
