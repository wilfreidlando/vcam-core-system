<?php

declare(strict_types=1);

namespace Core\Billing\Tests\Unit\Domain;

use Core\Billing\Domain\Exception\InvalidBillingInterval;
use Core\Billing\Domain\Model\BillingInterval;
use Core\Billing\Domain\Model\BillingIntervalUnit;
use Core\Billing\Domain\Model\BillingSettings;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ADR-0071 §4 — the arithmetic of the periods, without ambiguity.
 *
 * - `day` and `week` are plain calendar additions;
 * - `month` and `year` keep the day of the anchor and clamp it to the end
 *   of the target month when that day does not exist there (an anchor on the
 *   31st gives 28/29 February, then 31 March again);
 * - every shift is computed from the ORIGINAL anchor, never from the date
 *   the previous shift was clamped to;
 * - the arithmetic happens in the timezone of the project, the result is
 *   returned in UTC.
 *
 * These are pure PHP tests: no database, no framework.
 */
final class BillingIntervalTest extends TestCase
{
    #[Test]
    public function a_month_keeps_the_day_of_the_anchor_and_clamps_to_the_end_of_the_month(): void
    {
        $interval = BillingInterval::of(BillingIntervalUnit::Month, 1);
        $anchor = new DateTimeImmutable('2026-01-31 09:00:00', new DateTimeZone('UTC'));

        self::assertSame(
            '2026-02-28 09:00:00',
            $interval->endOf(anchor: $anchor, zone: self::utc())->format('Y-m-d H:i:s'),
        );

        // Two periods forward: the anchor day is kept again — the 31st of
        // March, not the 28th of February plus a month.
        self::assertSame(
            '2026-03-31 09:00:00',
            $interval->endOf(anchor: $anchor, zone: self::utc(), periods: 2)->format('Y-m-d H:i:s'),
        );
    }

    #[Test]
    public function the_same_anchor_in_a_leap_year_gives_the_29th_of_february(): void
    {
        $interval = BillingInterval::of(BillingIntervalUnit::Month, 1);
        $anchor = new DateTimeImmutable('2028-01-31 09:00:00', new DateTimeZone('UTC'));

        self::assertSame(
            '2028-02-29 09:00:00',
            $interval->endOf(anchor: $anchor, zone: self::utc())->format('Y-m-d H:i:s'),
        );
    }

    #[Test]
    public function a_leap_day_anchor_gives_the_28th_of_february_the_next_year(): void
    {
        $interval = BillingInterval::of(BillingIntervalUnit::Year, 1);
        $anchor = new DateTimeImmutable('2028-02-29 09:00:00', new DateTimeZone('UTC'));

        self::assertSame(
            '2029-02-28 09:00:00',
            $interval->endOf(anchor: $anchor, zone: self::utc())->format('Y-m-d H:i:s'),
        );
    }

    #[Test]
    public function a_day_is_a_plain_calendar_addition(): void
    {
        $anchor = new DateTimeImmutable('2026-01-31 09:00:00', new DateTimeZone('UTC'));

        self::assertSame(
            '2026-02-01 09:00:00',
            BillingInterval::of(BillingIntervalUnit::Day, 1)
                ->endOf(anchor: $anchor, zone: self::utc())->format('Y-m-d H:i:s'),
        );

        self::assertSame(
            '2026-02-03 09:00:00',
            BillingInterval::of(BillingIntervalUnit::Day, 3)
                ->endOf(anchor: $anchor, zone: self::utc())->format('Y-m-d H:i:s'),
        );
    }

    #[Test]
    public function a_week_is_a_plain_calendar_addition(): void
    {
        $anchor = new DateTimeImmutable('2026-01-31 09:00:00', new DateTimeZone('UTC'));

        self::assertSame(
            '2026-02-07 09:00:00',
            BillingInterval::of(BillingIntervalUnit::Week, 1)
                ->endOf(anchor: $anchor, zone: self::utc())->format('Y-m-d H:i:s'),
        );

        self::assertSame(
            '2026-02-14 09:00:00',
            BillingInterval::of(BillingIntervalUnit::Week, 2)
                ->endOf(anchor: $anchor, zone: self::utc())->format('Y-m-d H:i:s'),
        );
    }

    #[Test]
    public function every_shift_is_computed_from_the_original_anchor(): void
    {
        // The third period is not the second period plus a month: the anchor
        // is kept, not the date the first shift was clamped to.
        $interval = BillingInterval::of(BillingIntervalUnit::Month, 1);
        $anchor = new DateTimeImmutable('2026-01-31 09:00:00', new DateTimeZone('UTC'));

        self::assertSame(
            '2026-04-30 09:00:00',
            $interval->endOf(anchor: $anchor, zone: self::utc(), periods: 3)->format('Y-m-d H:i:s'),
        );
    }

    #[Test]
    public function the_start_of_a_period_is_the_anchor_walked_backwards(): void
    {
        $interval = BillingInterval::of(BillingIntervalUnit::Month, 1);
        $anchor = new DateTimeImmutable('2026-03-31 09:00:00', new DateTimeZone('UTC'));

        self::assertSame(
            '2026-02-28 09:00:00',
            $interval->startOf(anchor: $anchor, zone: self::utc())->format('Y-m-d H:i:s'),
        );
    }

    #[Test]
    public function the_arithmetic_happens_in_the_timezone_of_the_project(): void
    {
        $zone = BillingSettings::defaultsFor('project-1')->zone();

        // The very same instant, written in the timezone of the project and
        // in UTC: the day is the same in both representations, so the period
        // ends on the same day.
        $anchorInProjectZone = new DateTimeImmutable('2026-01-31 12:00:00', $zone);
        $anchorInUtc = new DateTimeImmutable('2026-01-31 11:00:00', new DateTimeZone('UTC'));

        self::assertSame(
            $anchorInProjectZone->getTimestamp(),
            $anchorInUtc->getTimestamp(),
            'the two anchors must be the same instant.',
        );

        $interval = BillingInterval::of(BillingIntervalUnit::Month, 1);

        self::assertSame(
            $interval->endOf(anchor: $anchorInProjectZone, zone: $zone)->format('Y-m-d H:i:s'),
            $interval->endOf(anchor: $anchorInUtc, zone: $zone)->format('Y-m-d H:i:s'),
        );

        self::assertSame(
            '2026-02-28',
            $interval->endOf(anchor: $anchorInProjectZone, zone: $zone)
                ->setTimezone($zone)->format('Y-m-d'),
        );
    }

    #[Test]
    public function the_local_day_is_kept_when_the_utc_day_is_another_one(): void
    {
        $zone = BillingSettings::defaultsFor('project-1')->zone();

        // Africa/Douala is UTC+1: this anchor is the 31st locally and the
        // 30th in UTC. The day of the project is the one that is kept.
        $anchor = new DateTimeImmutable('2026-01-31 00:30:00', $zone);

        self::assertSame(
            '2026-01-30',
            $anchor->setTimezone(self::utc())->format('Y-m-d'),
            'the anchor is the previous day in UTC.',
        );

        $end = BillingInterval::of(BillingIntervalUnit::Month, 1)->endOf(anchor: $anchor, zone: $zone);

        self::assertSame(
            '2026-02-28',
            $end->setTimezone($zone)->format('Y-m-d'),
            'the local day of the anchor is the one that is kept.',
        );

        self::assertSame(
            '2026-02-27 23:30:00',
            $end->format('Y-m-d H:i:s'),
        );
    }

    #[Test]
    public function the_result_is_returned_in_utc(): void
    {
        $zone = BillingSettings::defaultsFor('project-1')->zone();
        $anchor = new DateTimeImmutable('2026-01-31 12:00:00', $zone);

        $end = BillingInterval::of(BillingIntervalUnit::Month, 1)->endOf(anchor: $anchor, zone: $zone);

        self::assertSame('UTC', $end->getTimezone()->getName());
    }

    #[Test]
    public function a_count_below_one_is_refused(): void
    {
        $this->expectException(InvalidBillingInterval::class);

        BillingInterval::of(BillingIntervalUnit::Month, 0);
    }

    #[Test]
    public function a_negative_count_is_refused(): void
    {
        $this->expectException(InvalidBillingInterval::class);

        BillingInterval::of(BillingIntervalUnit::Day, -3);
    }

    #[Test]
    public function a_number_of_periods_below_one_is_refused(): void
    {
        $this->expectException(InvalidBillingInterval::class);

        BillingInterval::of(BillingIntervalUnit::Month, 1)->endOf(
            anchor: new DateTimeImmutable('2026-01-31 09:00:00', self::utc()),
            zone: self::utc(),
            periods: 0,
        );
    }

    private static function utc(): DateTimeZone
    {
        return new DateTimeZone('UTC');
    }
}
