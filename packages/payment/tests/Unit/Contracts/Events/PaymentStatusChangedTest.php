<?php

declare(strict_types=1);

namespace Core\Payment\Tests\Unit\Infrastructure\HttpAdapter\MyCoolPay;

use Core\Payment\Contracts\Events\PaymentStatusChanged;
use Core\Payment\PaymentStatus;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PaymentStatusChangedTest extends TestCase
{
    #[Test]
    public function to_payload_round_trip(): void
    {
        $event = new PaymentStatusChanged(
            providerCode: 'my-coolpay',
            transactionRef: 'tx-1',
            appTransactionRef: 'order-42',
            status: PaymentStatus::Succeeded,
            reason: 'paid by OM',
            occurredAt: '2026-09-15T10:30:00Z',
        );

        $payload = $event->toPayload();
        self::assertSame('my-coolpay', $payload['provider_code']);
        self::assertSame('tx-1', $payload['transaction_ref']);
        self::assertSame('order-42', $payload['app_transaction_ref']);
        self::assertSame('succeeded', $payload['status']);
        self::assertSame('paid by OM', $payload['reason']);
        self::assertSame('2026-09-15T10:30:00Z', $payload['occurred_at']);

        $reconstructed = PaymentStatusChanged::fromPayload($payload);
        self::assertSame('my-coolpay', $reconstructed->providerCode);
        self::assertSame('tx-1', $reconstructed->transactionRef);
        self::assertSame('order-42', $reconstructed->appTransactionRef);
        self::assertSame(PaymentStatus::Succeeded, $reconstructed->status);
        self::assertSame('paid by OM', $reconstructed->reason);
        self::assertSame('2026-09-15T10:30:00Z', $reconstructed->occurredAt);
    }

    #[Test]
    public function from_payload_handles_missing_optional_fields(): void
    {
        $event = PaymentStatusChanged::fromPayload([
            'provider_code' => 'my-coolpay',
            'transaction_ref' => 'tx-2',
            'app_transaction_ref' => 'order-43',
            'status' => 'failed',
        ]);

        self::assertSame(PaymentStatus::Failed, $event->status);
        self::assertNull($event->reason);
        self::assertNull($event->occurredAt);
    }

    #[Test]
    public function from_payload_rejects_unknown_status(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PaymentStatusChanged::fromPayload([
            'provider_code' => 'my-coolpay',
            'transaction_ref' => 'tx-3',
            'app_transaction_ref' => 'order-44',
            'status' => 'weird-new-status',
        ]);
    }
}
