<?php

declare(strict_types=1);

namespace App\Billing;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The « Gérer mon abonnement » button: one call, one redirect. The customer
 * lands on the hosted portal (invoices, payment, usage), then comes back.
 *
 * Only a user who may manage billing in YOUR app should reach this — guard
 * the route with your own authorisation (e.g. `can:manage-billing`).
 */
final class BillingController
{
    public function index(Request $request, CoreBilling $billing): RedirectResponse
    {
        $ref = EnsureTenantSubscribed::tenantRef($request);
        abort_if($ref === null, 403);

        $tenant = $request->user()->tenant;   // ADAPT: your tenant model

        return redirect()->away($billing->portalUrl(
            $ref,
            tenant: ['name' => $tenant->name, 'email' => $tenant->email, 'phone' => $tenant->phone],
            planCode: config('core_billing.default_plan'),
        ));
    }
}
