<?php

declare(strict_types=1);

namespace App\Billing;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks a tenant that has no right to be here.
 *
 *   Route::middleware('subscribed')->group(...);              // any active plan
 *   Route::middleware('subscribed:whatsapp')->group(...);     // a feature of the plan
 *
 * Register the alias in bootstrap/app.php:
 *   $middleware->alias(['subscribed' => \App\Billing\EnsureTenantSubscribed::class]);
 *
 * The tenant of a request is resolved by YOUR code, in one place:
 * `CoreBilling::tenantRef()` below — adapt that single line.
 */
final class EnsureTenantSubscribed
{
    public function __construct(private readonly CoreBilling $billing) {}

    public function handle(Request $request, Closure $next, ?string $feature = null): Response
    {
        $ref = self::tenantRef($request);

        // Fail closed: no tenant, no access (ADR-0084 §4).
        if ($ref === null || ! $this->billing->canUse($ref, $feature)) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Subscription required.'], 402)
                : redirect()->route('billing.index')->with('billing_notice', 'Votre abonnement ne permet pas d\'accéder à cette page.');
        }

        return $next($request);
    }

    /**
     * ADAPT THIS: the reference of the tenant of the current request — the
     * same value you pass to `provision()`.
     */
    public static function tenantRef(Request $request): ?string
    {
        $tenantId = $request->user()?->tenant_id ?? null;

        return $tenantId === null ? null : (string) $tenantId;
    }
}
