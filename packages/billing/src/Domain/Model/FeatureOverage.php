<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Model;

/**
 * ADR-0071 §3 — what happens past the limit.
 *
 * - `block` — the call is refused beyond the limit;
 * - `allow` — the consumption is recorded and signalled, never blocked.
 */
enum FeatureOverage: string
{
    case Block = 'block';
    case Allow = 'allow';
}
