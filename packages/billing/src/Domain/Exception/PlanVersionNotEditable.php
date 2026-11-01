<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Exception;

use RuntimeException;

/**
 * ADR-0071 §2 — a published plan version is immutable.
 *
 * The price, the currency, the interval, the trial, the grace and the
 * features are frozen the moment the version is published: changing a
 * price means publishing a NEW version, and the contracts already signed
 * keep the terms they were signed on.
 *
 * The database refuses the write too (the `plan_version_immutable`
 * trigger); this exception is the same rule, stated before the round trip.
 */
final class PlanVersionNotEditable extends RuntimeException
{
    public static function becausePublished(int $version): self
    {
        return new self(
            "Plan version {$version} is published and immutable: publish a new version instead (ADR-0071 §2).",
        );
    }
}
