<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Model;

/**
 * ADR-0074 §4 — how a metered call is taken.
 *
 * `Enforce` refuses beyond the limit; `Record` states a fact that already
 * happened (an a-posteriori finding) and is written even beyond it.
 */
enum UsageMode: string
{
    case Enforce = 'enforce';
    case Record = 'record';
}
