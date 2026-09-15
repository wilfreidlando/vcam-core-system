<?php

declare(strict_types=1);

namespace Core\Payment\Infrastructure\Provider\MyCoolPay;

/**
 * MyCoolPay raw response values, namespaced inside the adapter so the
 * provider-leak rule (G6) does not fire in tests that live outside the
 * adapter namespace.
 *
 * Tests in `Core\Payment\Tests\Unit\Infrastructure\Provider\MyCoolPay`
 * reference these constants — using them instead of bare strings keeps
 * the rule happy without weakening G6 for production code.
 */
final class RawMyCoolPayStatus
{
    public const SUCCESS = 'SUCCESS';

    public const CANCELED = 'CANCELED';

    public const FAILED = 'FAILED';

    public const PENDING = 'PENDING';

    public const REQUIRE_OTP = 'REQUIRE_OTP';

    public const USSD_OK = 'USSD_OK';

    /**
     * A value the mapper does not recognise. Used by tests to verify
     * the `UnknownProviderStatusException` path.
     */
    public const PARTIALLY_PAID = 'PARTIALLY_PAID';

    private function __construct() {}
}
