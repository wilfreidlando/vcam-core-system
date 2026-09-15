<?php

declare(strict_types=1);

namespace Core\Notification\Tests\Unit\Infrastructure\Provider\Waha;

use Core\Notification\Infrastructure\Provider\Waha\RawWahaStatus;
use Core\Notification\Infrastructure\Provider\Waha\WahaStatusMapper;
use Core\Notification\NotificationStatus;
use Core\Support\Integration\Exception\UnknownProviderStatusException;
use Core\Support\Integration\Provider\ProviderCode;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WahaStatusMapperTest extends TestCase
{
    private WahaStatusMapper $mapper;

    #[Test]
    public function error_maps_to_failed(): void
    {
        self::assertSame(NotificationStatus::Failed, $this->mapper->map(RawWahaStatus::ERROR));
    }

    #[Test]
    public function pending_maps_to_sent(): void
    {
        self::assertSame(NotificationStatus::Sent, $this->mapper->map(RawWahaStatus::PENDING));
    }

    #[Test]
    public function sent_maps_to_sent(): void
    {
        self::assertSame(NotificationStatus::Sent, $this->mapper->map(RawWahaStatus::SENT));
    }

    #[Test]
    public function delivered_maps_to_delivered(): void
    {
        self::assertSame(NotificationStatus::Delivered, $this->mapper->map(RawWahaStatus::DELIVERED));
    }

    #[Test]
    public function read_maps_to_read(): void
    {
        self::assertSame(NotificationStatus::Read, $this->mapper->map(RawWahaStatus::READ));
    }

    #[Test]
    public function played_maps_to_read(): void
    {
        self::assertSame(NotificationStatus::Read, $this->mapper->map(RawWahaStatus::PLAYED));
    }

    #[Test]
    public function unknown_value_throws(): void
    {
        $this->expectException(UnknownProviderStatusException::class);
        $this->mapper->map('99');
    }

    protected function setUp(): void
    {
        $this->mapper = new WahaStatusMapper(ProviderCode::fromString('whatsapp-waha'));
    }
}
