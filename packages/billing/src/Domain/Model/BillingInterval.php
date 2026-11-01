<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Model;

use Core\Billing\Domain\Exception\InvalidBillingInterval;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

/**
 * ADR-0071 §4 — the arithmetic of the periods, without ambiguity.
 *
 * The interval is `unit` + `count`, and the two families of unit do NOT
 * behave the same:
 *
 * - `day` and `week` are plain calendar additions in the timezone of the
 *   project;
 * - `month` and `year` keep the **day of the anchor** and clamp it to the
 *   end of the target month when that day does not exist there (an anchor
 *   on the 31st gives 28/29 February, then 31 March again).
 *
 * The clamping is done here rather than by `DateInterval`, because PHP's
 * own arithmetic OVERFLOWS: `2026-01-31 + P1M` is `2026-03-03`, not
 * `2026-02-28`. Overflowing would make the second period of a contract
 * start on the 3rd of March instead of the 28th of February, and the
 * anchor of the contract would drift with it.
 *
 * Every shift is computed from the ORIGINAL anchor, never from the
 * previously shifted date (ADR-0071 §4): the anchor is kept, not the date
 * it was adjusted to.
 *
 * The bounds are returned in UTC (that is how the periods are stored),
 * while the arithmetic happens in the timezone of the project.
 */
final readonly class BillingInterval
{
    public function __construct(
        public BillingIntervalUnit $unit,
        public int $count,
    ) {
        if ($count < 1) {
            throw InvalidBillingInterval::becauseCountBelowOne($count);
        }
    }

    public static function of(BillingIntervalUnit $unit, int $count): self
    {
        return new self($unit, $count);
    }

    /**
     * The **exclusive** end of the period that starts at the anchor: the
     * instant the next period begins.
     *
     * The bound is exclusive because the periods of a contract are
     * half-open intervals — `starts_at <= moment < ends_at` — so that two
     * consecutive periods never overlap (ADR-0072 §1, the exclusion
     * constraint on `subscription_periods`).
     *
     * @param int $periods how many periods to walk forward, at least one
     */
    public function endOf(DateTimeImmutable $anchor, DateTimeZone $zone, int $periods = 1): DateTimeImmutable
    {
        $steps = $this->count * self::assertPeriods($periods);

        return match ($this->unit) {
            BillingIntervalUnit::Day => $this->shiftDays($anchor, $zone, $steps),
            BillingIntervalUnit::Week => $this->shiftDays($anchor, $zone, $steps * 7),
            BillingIntervalUnit::Month => $this->shiftMonths($anchor, $zone, $steps),
            BillingIntervalUnit::Year => $this->shiftMonths($anchor, $zone, $steps * 12),
        };
    }

    /**
     * The start of the period that ends at the anchor — the same walk,
     * backwards.
     *
     * @param int $periods how many periods to walk back, at least one
     */
    public function startOf(DateTimeImmutable $anchor, DateTimeZone $zone, int $periods = 1): DateTimeImmutable
    {
        $steps = $this->count * self::assertPeriods($periods);

        return match ($this->unit) {
            BillingIntervalUnit::Day => $this->shiftDays($anchor, $zone, -$steps),
            BillingIntervalUnit::Week => $this->shiftDays($anchor, $zone, -$steps * 7),
            BillingIntervalUnit::Month => $this->shiftMonths($anchor, $zone, -$steps),
            BillingIntervalUnit::Year => $this->shiftMonths($anchor, $zone, -$steps * 12),
        };
    }

    /**
     * A plain calendar addition of days, in the timezone of the project.
     *
     * `DateInterval` is used through its `invert` flag rather than through
     * a negative ISO string, which the parser refuses.
     */
    private function shiftDays(DateTimeImmutable $anchor, DateTimeZone $zone, int $days): DateTimeImmutable
    {
        $interval = new DateInterval('P'.abs($days).'D');

        if ($days < 0) {
            $interval->invert = 1;
        }

        return $anchor
            ->setTimezone($zone)
            ->add($interval)
            ->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * The month arithmetic of ADR-0071 §4, done by hand: the target year
     * and month are computed from the anchor, the day is clamped to the
     * last day of the target month, and the time of day and the timezone
     * of the anchor are kept.
     */
    private function shiftMonths(DateTimeImmutable $anchor, DateTimeZone $zone, int $months): DateTimeImmutable
    {
        $local = $anchor->setTimezone($zone);

        $day = (int) $local->format('j');
        $time = $local->format('H:i:s');

        // The month index is absolute (year * 12 + month) so that the walk
        // crosses the years without a special case for December.
        $monthIndex = ((int) $local->format('Y')) * 12 + ((int) $local->format('n')) - 1 + $months;

        // A floor division, not a truncation: the walk goes backwards too.
        $year = (int) floor($monthIndex / 12);
        $month = $monthIndex - ($year * 12) + 1;

        $clampedDay = min($day, self::daysInMonth($year, $month));

        $target = new DateTimeImmutable(
            sprintf('%04d-%02d-%02d %s', $year, $month, $clampedDay, $time),
            $zone,
        );

        return $target->setTimezone(new DateTimeZone('UTC'));
    }

    private static function daysInMonth(int $year, int $month): int
    {
        $firstOfMonth = new DateTimeImmutable(
            sprintf('%04d-%02d-01', $year, $month),
            new DateTimeZone('UTC'),
        );

        return (int) $firstOfMonth->format('t');
    }

    private static function assertPeriods(int $periods): int
    {
        if ($periods < 1) {
            throw InvalidBillingInterval::becauseCountBelowOne($periods);
        }

        return $periods;
    }
}
