<?php

declare(strict_types=1);

namespace App\Core\Billing;

use App\Core\CoreClient;
use App\Core\CoreException;
use Illuminate\Support\Facades\Cache;

/**
 * The whole commerce integration of a SaaS, in one class.
 *
 * Your SaaS keeps its tenants and its users. The Core keeps the money: plans,
 * subscriptions, invoices, quotas. Here a tenant is a « customer » of the
 * Core, named by the reference YOU choose (its id, its code…).
 *
 *   provision()  – once, when a tenant is created
 *   portalUrl()  – the « Gérer mon abonnement » button
 *   canUse()     – is this tenant allowed in? (cached 60 s)
 *   consume()    – spend a unit of a quota (refused beyond the plan)
 */
final class CoreBilling
{
    public function __construct(private readonly CoreClient $core) {}

    /**
     * Declare the tenant and start its plan (a free trial by default).
     * Safe to call twice: the customer is an upsert and the subscription is
     * idempotent on its key.
     *
     * @param array{name: string, email?: string|null, phone?: string|null} $tenant
     */
    public function provision(string $ref, array $tenant, ?string $planCode = null, ?int $trialDays = null): void
    {
        $plan = $planCode ?? config('core.billing.default_plan');

        $this->core->put('customers/'.rawurlencode($ref), [
            'display_name' => $tenant['name'],
            'email' => $tenant['email'] ?? null,
            'phone' => $tenant['phone'] ?? null,
        ]);

        $this->core->post('subscriptions', [
            'customer_ref' => $ref,
            'plan_code' => $plan,
            'trial_days' => $trialDays ?? config('core.billing.trial_days'),
        ], "provision:{$ref}:{$plan}");

        Cache::forget($this->cacheKey($ref));
    }

    /**
     * The link to send the tenant's browser to. It is valid ten minutes and
     * works once: call this when the user CLICKS, never store it.
     *
     * Passing `$tenant`/`$planCode` makes this the only call you need: the
     * tenant is declared and subscribed on the way (only if it has no live
     * contract on that plan), so it is safe on every click.
     *
     * @param array{name?: string, email?: string|null, phone?: string|null}|null $tenant
     */
    public function portalUrl(string $ref, ?array $tenant = null, ?string $planCode = null, ?string $flow = null, ?string $invoiceId = null): string
    {
        $payload = array_filter([
            'customer_ref' => $ref,
            'flow' => $flow,
            'invoice_id' => $invoiceId,
            'return_url' => config('core.billing.return_url'),
            'customer' => $tenant === null ? null : array_filter([
                'display_name' => $tenant['name'] ?? null,
                'email' => $tenant['email'] ?? null,
                'phone' => $tenant['phone'] ?? null,
            ], static fn ($v): bool => $v !== null),
            'plan_code' => $planCode,
            'trial_days' => $planCode === null ? null : config('core.billing.trial_days'),
        ], static fn ($v): bool => $v !== null);

        return (string) ($this->core->post('portal/sessions', $payload)['url'] ?? '');
    }

    /**
     * What the tenant may do right now: `active`, the plan, every feature and
     * every quota with what is left. Cached for 60 s; webhooks clear it.
     *
     * @return array{active: bool, plan_code: string|null, entitlements: list<array<string, mixed>>}
     */
    public function entitlements(string $ref): array
    {
        return Cache::remember($this->cacheKey($ref), (int) config('core.billing.entitlements_ttl'), function () use ($ref): array {
            try {
                /** @var array{active: bool, plan_code: string|null, entitlements: list<array<string, mixed>>} */
                return $this->core->get('customers/'.rawurlencode($ref).'/entitlements');
            } catch (CoreException $e) {
                // Fail closed: a tenant the Core does not know has no rights.
                if ($e->status === 404) {
                    return ['active' => false, 'plan_code' => null, 'entitlements' => []];
                }

                throw $e;
            }
        });
    }

    public function canUse(string $ref, ?string $feature = null): bool
    {
        $rights = $this->entitlements($ref);

        if (! $rights['active']) {
            return false;
        }

        if ($feature === null) {
            return true;
        }

        foreach ($rights['entitlements'] as $entitlement) {
            if ($entitlement['key'] === $feature) {
                return $entitlement['type'] === 'boolean' ? (bool) $entitlement['enabled'] : true;
            }
        }

        return false;
    }

    /**
     * Spend `$quantity` units of a quota. Throws QuotaExceeded beyond the
     * plan — nothing is counted in that case. `$key` makes the call safe to
     * replay: the same key counts once (use the id of the thing you create).
     * A negative quantity releases a unit (a student leaves).
     *
     * @return array{status: string, used: int, limit: int|null, remaining: int|null}
     */
    public function consume(string $ref, string $meter, string $key, int $quantity = 1): array
    {
        try {
            /** @var array{status: string, used: int, limit: int|null, remaining: int|null} $result */
            $result = $this->core->post('usage', [
                'customer_ref' => $ref,
                'meter' => $meter,
                'quantity' => $quantity,
                'idempotency_key' => $key,
            ]);
        } catch (CoreException $e) {
            // 429 `quota-exceeded` is the plan's limit (nothing was counted);
            // a 429 `rate-limit-exceeded` is the platform asking you to slow down.
            if ($e->status === 429 && $e->is('quota-exceeded')) {
                throw new QuotaExceeded($meter, (int) ($e->body['used'] ?? 0), isset($e->body['limit']) ? (int) $e->body['limit'] : null);
            }

            throw $e;
        }

        Cache::forget($this->cacheKey($ref));

        return $result;
    }

    public function forget(string $ref): void
    {
        Cache::forget($this->cacheKey($ref));
    }

    private function cacheKey(string $ref): string
    {
        return "core_billing:entitlements:{$ref}";
    }
}
