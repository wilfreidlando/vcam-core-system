<?php

declare(strict_types=1);

namespace Tests\Unit\Notices;

use App\Notices\CoreNoticeRenderer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ADR-0118 §3 — the words of the Core's notices: French, readable, naming
 * the project, the object and the customer.
 */
final class CoreNoticeRendererTest extends TestCase
{
    #[Test]
    public function it_writes_the_renewal_reminder_with_the_date_the_period_ends(): void
    {
        $text = (new CoreNoticeRenderer)->render(
            'subscription.renewal_due',
            [
                'customer_ref' => 'client-1',
                'current_period' => [
                    'starts_at' => '2026-03-01T00:00:00+00:00',
                    'ends_at' => '2026-03-12T00:00:00+00:00',
                ],
            ],
            'Auto-école',
            'Awa',
        );

        self::assertSame(
            'Bonjour Awa, votre abonnement Auto-école se renouvelle le 12/03/2026. La facture du renouvellement a été émise.',
            $text,
        );
    }

    #[Test]
    public function it_writes_the_quota_limit_with_the_counters_and_the_meter(): void
    {
        $text = (new CoreNoticeRenderer)->render(
            'usage.limit_reached',
            [
                'customer_ref' => 'client-1',
                'meter' => 'messages',
                'used' => 100,
                'limit' => 100,
                'threshold_bps' => 8000,
                'window_start' => '2026-11-01T00:00:00+00:00',
                'window_end' => '2026-11-30T23:59:59+00:00',
            ],
            'Auto-école',
            'Awa',
        );

        self::assertSame(
            'Bonjour Awa, vous avez atteint la limite « messages » sur Auto-école (100/100).',
            $text,
        );
    }

    #[Test]
    public function it_writes_the_threshold_as_a_percentage(): void
    {
        $text = (new CoreNoticeRenderer)->render(
            'usage.threshold_reached',
            ['customer_ref' => 'client-1', 'meter' => 'messages', 'threshold_bps' => 8000],
            'Auto-école',
            'Awa',
        );

        self::assertSame(
            'Bonjour Awa, vous avez atteint 80 % de votre limite « messages » sur Auto-école.',
            $text,
        );
    }

    #[Test]
    public function it_writes_the_invoice_with_its_number_and_its_amount(): void
    {
        $text = (new CoreNoticeRenderer)->render(
            'invoice.issued',
            [
                'customer_ref' => 'client-1',
                'invoice_number' => 'FACT-2026-000042',
                'total_minor' => 25000,
                'currency' => 'XAF',
                'due_at' => '2026-03-12T00:00:00+00:00',
            ],
            'Auto-école',
            'Awa',
        );

        self::assertSame(
            'Bonjour Awa, la facture FACT-2026-000042 pour Auto-école (25 000 XAF) est disponible.',
            $text,
        );
    }

    #[Test]
    public function it_says_nothing_for_an_event_it_does_not_know(): void
    {
        self::assertNull(
            (new CoreNoticeRenderer)->render('payment.status_changed', ['customer_ref' => 'client-1'], 'Auto-école', 'Awa'),
        );
    }

    #[Test]
    public function it_writes_to_somebody_whose_name_is_unknown(): void
    {
        $text = (new CoreNoticeRenderer)->render(
            'subscription.expired',
            ['customer_ref' => 'client-1', 'current_period' => ['ends_at' => '2026-03-12T00:00:00+00:00']],
            'Auto-école',
            '',
        );

        self::assertSame(
            'Bonjour, votre abonnement Auto-école est arrivé à échéance le 12/03/2026.',
            $text,
        );
    }
}
