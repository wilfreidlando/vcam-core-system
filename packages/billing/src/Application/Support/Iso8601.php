<?php

declare(strict_types=1);

namespace Core\Billing\Application\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The wire format of a timestamp: ISO-8601, in UTC.
 *
 * The periods are computed in the timezone of the project and stored in
 * UTC (ADR-0071 §4); the read models of `Contracts/` therefore carry UTC
 * instants, whatever the project timezone is.
 */
final readonly class Iso8601
{
    public static function of(DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    public static function ofNullable(?DateTimeImmutable $moment): ?string
    {
        return $moment === null ? null : self::of($moment);
    }
}
