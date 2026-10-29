<?php

declare(strict_types=1);

namespace App\Billing;

/**
 * THE ONLY BUSINESS CODE YOU WRITE: what happens in YOUR app when the state
 * of a tenant's subscription changes. The Core never calls your app to change
 * anything (ADR-0084 §1): it tells you, and you decide.
 *
 * `$state` is one of: `active`, `warning` (payment late, still allowed),
 * `blocked`. Rights are always re-read from the Core, so a missed event
 * costs at most 60 s (the cache), never a wrong state.
 */
final class TenantAccess
{
    public static function apply(string $tenantRef, string $state, string $reason): void
    {
        // Example — adapt to your model:
        //
        // \App\Models\Tenant::whereKey($tenantRef)->update([
        //     'billing_state' => $state,          // show a banner on 'warning'
        //     'is_active'     => $state !== 'blocked',
        // ]);
        //
        // \Log::info('billing.access', compact('tenantRef', 'state', 'reason'));
    }
}
