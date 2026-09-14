<?php

declare(strict_types=1);

namespace Core\Support\Tests\Unit\Integration\Provider;

use Core\Support\Integration\Provider\ProviderCode;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProviderCodeTest extends TestCase
{
    #[Test]
    public function it_accepts_a_normal_code(): void
    {
        $code = ProviderCode::fromString('mtn-momo');

        self::assertSame('mtn-momo', $code->value);
        self::assertSame('mtn-momo', $code->toString());
        self::assertSame('mtn-momo', (string) $code);
    }

    #[Test]
    public function it_lowercases_the_code(): void
    {
        $code = ProviderCode::fromString('MTN-Momo');

        self::assertSame('mtn-momo', $code->value);
    }

    #[Test]
    public function it_trims_surrounding_whitespace(): void
    {
        $code = ProviderCode::fromString('  cinetpay  ');

        self::assertSame('cinetpay', $code->value);
    }

    #[Test]
    public function it_rejects_an_empty_string(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ProviderCode::fromString('');
    }

    #[Test]
    public function it_rejects_whitespace_inside(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ProviderCode::fromString('mtn momo');
    }

    #[Test]
    public function equality_is_by_value(): void
    {
        $a = ProviderCode::fromString('mtn-momo');
        $b = ProviderCode::fromString('MTN-MOMO');

        self::assertTrue($a->equals($b));
        self::assertFalse($a->equals(ProviderCode::fromString('cinetpay')));
    }
}
