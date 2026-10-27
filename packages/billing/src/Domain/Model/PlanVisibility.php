<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Model;

/**
 * ADR-0071 §1 — who a plan is proposed to.
 *
 * - `public` — proposed in the portal;
 * - `private` — attributed only, never listed.
 */
enum PlanVisibility: string
{
    case Public = 'public';
    case Private = 'private';
}
