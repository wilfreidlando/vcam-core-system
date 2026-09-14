<?php

declare(strict_types=1);

namespace Core\Support\Tests\Unit\Integration\Exception;

use Core\Support\Integration\Exception\ProviderException;
use Core\Support\Integration\Exception\UnknownProviderStatusException;
use Core\Support\Integration\Provider\ProviderCode;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class UnknownProviderStatusExceptionTest extends TestCase
{
    #[Test]
    public function it_carries_the_provider_code_and_raw_status(): void
    {
        $code = ProviderCode::fromString('mtn-momo');
        $exception = UnknownProviderStatusException::forValue($code, 'WEIRD_NEW_STATUS');

        self::assertInstanceOf(ProviderException::class, $exception);
        self::assertTrue($exception->providerCode->equals($code));
        self::assertSame('WEIRD_NEW_STATUS', $exception->rawStatus);
        self::assertStringContainsString('mtn-momo', $exception->getMessage());
        self::assertStringContainsString('WEIRD_NEW_STATUS', $exception->getMessage());
    }

    #[Test]
    public function it_can_be_constructed_directly(): void
    {
        $code = ProviderCode::fromString('cinetpay');
        $exception = new UnknownProviderStatusException(
            'manual message',
            $code,
            '201',
        );

        self::assertSame('201', $exception->rawStatus);
        self::assertSame('manual message', $exception->getMessage());
    }
}
