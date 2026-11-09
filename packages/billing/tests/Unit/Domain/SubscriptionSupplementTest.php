<?php

declare(strict_types=1);

namespace Core\Billing\Tests\Unit\Domain;

use Core\Billing\Domain\Exception\InvalidSubscriptionSupplement;
use Core\Billing\Domain\Model\EffectiveLimits;
use Core\Billing\Domain\Model\SubscriptionSupplement;
use Core\Billing\Domain\Model\SubscriptionSupplementStatus;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ADR-0117 §2, §3 — the supplement, without a database and without a
 * framework.
 *
 * The rules pinned here are the ones the purchase path relies on: the
 * terms are frozen at the purchase, a purchase that was not paid for
 * grants nothing, and the effective limit is the sum of the allowances.
 */
final class SubscriptionSupplementTest extends TestCase
{
    #[Test]
    public function a_purchase_is_pending_and_carries_its_frozen_terms(): void
    {
        $supplement = $this->purchase();

        self::assertSame(SubscriptionSupplementStatus::Pending, $supplement->status);
        self::assertFalse($supplement->grantsAllowance());
        self::assertNull($supplement->invoiceId);
        self::assertSame('whatsapp_messages', $supplement->meter);
        self::assertSame(50, $supplement->quantity);
        self::assertSame(5_000, $supplement->unitPriceMinor);
        self::assertSame('XAF', $supplement->currency);
        self::assertSame(250_000, $supplement->totalMinor());
    }

    #[Test]
    public function a_quantity_that_is_not_a_positive_number_of_units_is_refused(): void
    {
        $this->expectException(InvalidSubscriptionSupplement::class);

        $this->purchase(quantity: 0);
    }

    #[Test]
    public function a_negative_price_is_refused(): void
    {
        $this->expectException(InvalidSubscriptionSupplement::class);

        $this->purchase(unitPriceMinor: -1);
    }

    #[Test]
    public function the_money_arriving_activates_the_supplement(): void
    {
        $supplement = $this->purchase();
        $now = new DateTimeImmutable('2026-11-02T09:00:00Z');

        $supplement->activate($now);

        self::assertSame(SubscriptionSupplementStatus::Active, $supplement->status);
        self::assertTrue($supplement->grantsAllowance());
        self::assertSame($now, $supplement->activatedAt);
    }

    #[Test]
    public function a_supplement_is_not_activated_twice(): void
    {
        $supplement = $this->purchase();
        $supplement->activate(new DateTimeImmutable('2026-11-02T09:00:00Z'));

        $this->expectException(InvalidSubscriptionSupplement::class);

        $supplement->activate(new DateTimeImmutable('2026-11-02T10:00:00Z'));
    }

    #[Test]
    public function an_annulled_purchase_never_granted_anything(): void
    {
        $supplement = $this->purchase();
        $now = new DateTimeImmutable('2026-11-02T09:00:00Z');

        $supplement->cancel($now);

        self::assertSame(SubscriptionSupplementStatus::Canceled, $supplement->status);
        self::assertFalse($supplement->grantsAllowance());
        self::assertSame($now, $supplement->canceledAt);
    }

    #[Test]
    public function the_effective_limit_is_the_limit_of_the_plan_plus_the_allowances(): void
    {
        $limits = new EffectiveLimits(['whatsapp_messages' => 50, 'sms' => 10]);

        self::assertSame(150, $limits->limitFor('whatsapp_messages', 100));
        self::assertSame(10, $limits->limitFor('sms', 0));
        self::assertSame(100, $limits->limitFor('emails', 100));
        self::assertSame(50, $limits->quantityFor('whatsapp_messages'));
        self::assertSame(0, $limits->quantityFor('emails'));
    }

    #[Test]
    public function an_unlimited_right_stays_unlimited(): void
    {
        $limits = new EffectiveLimits(['whatsapp_messages' => 50]);

        self::assertNull($limits->limitFor('whatsapp_messages', null));
    }

    private function purchase(int $quantity = 50, int $unitPriceMinor = 5_000): SubscriptionSupplement
    {
        return SubscriptionSupplement::purchase(
            id: '11111111-1111-4111-8111-111111111111',
            projectId: '22222222-2222-4222-8222-222222222222',
            subscriptionId: '33333333-3333-4333-8333-333333333333',
            customerId: '44444444-4444-4444-8444-444444444444',
            meter: 'whatsapp_messages',
            featureKey: 'messages',
            quantity: $quantity,
            unitPriceMinor: $unitPriceMinor,
            currency: 'XAF',
            periodId: '55555555-5555-4555-8555-555555555555',
            createdAt: new DateTimeImmutable('2026-11-01T09:00:00Z'),
        );
    }
}
