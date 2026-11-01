<?php

declare(strict_types=1);

namespace App\Core\Billing;

use App\Core\Webhooks\CoreEventReceived;
use Illuminate\Support\Facades\Cache;

/**
 * Turns the Core's subscription events into the state of YOUR tenant
 * (ADR-0084 §3). Register it once:
 *
 *   Event::listen(CoreEventReceived::class, ApplyBillingEvent::class);
 *
 * It ignores every event that is not about commerce.
 */
final class ApplyBillingEvent
{
    /** event type → [state to apply, reason] */
    private const EFFECTS = [
        'subscription.activated' => ['active', 'subscription activated'],
        'subscription.renewed' => ['active', 'subscription renewed'],
        'subscription.resumed' => ['active', 'subscription resumed or renewal kept'],
        'invoice.paid' => ['active', 'invoice paid'],
        'subscription.past_due' => ['warning', 'payment is late'],
        'invoice.payment_failed' => ['warning', 'a payment failed'],
        'subscription.paused' => ['blocked', 'subscription paused'],
        'subscription.canceled' => ['blocked', 'subscription canceled'],
        'subscription.expired' => ['blocked', 'subscription expired'],
        'customer.suspended' => ['blocked', 'customer suspended'],
        'customer.reactivated' => ['active', 'customer reactivated'],
    ];

    public function __construct(private readonly CoreBilling $billing) {}

    public function __invoke(CoreEventReceived $event): void
    {
        if (! $event->is('subscription') && ! $event->is('invoice') && ! $event->is('customer') && ! $event->is('usage')) {
            return;
        }

        $data = $event->data;
        $ref = $data['customer_ref'] ?? $data['external_ref'] ?? null;

        if (! is_string($ref)) {
            return;
        }

        $this->billing->forget($ref);                          // the next read is fresh

        // Delivery order is not guaranteed: ignore an event older than the last
        // one applied for this resource (`version`).
        if ($this->isStale($data, $event->type, $data['version'] ?? null)) {
            return;
        }

        if (isset(self::EFFECTS[$event->type])) {
            [$state, $reason] = self::EFFECTS[$event->type];

            // « Do not renew » is announced as `subscription.canceled` with
            // `cancel_at_period_end = true`: the tenant keeps its rights until the
            // period ends (then `subscription.expired` tells you). Never block on the request.
            if ($event->type === 'subscription.canceled' && ($data['cancel_at_period_end'] ?? false) === true) {
                [$state, $reason] = ['warning', 'subscription will not renew'];
            }

            TenantAccess::apply($ref, $state, $reason);
        }

        // subscription.trial_will_end (3 days before), usage.threshold_reached,
        // usage.limit_reached: warn your tenant here (banner, WhatsApp via
        // CoreNotifications…) — `$data['meter']`, `$data['used']`, `$data['limit']`.
    }

    /**
     * @param array<string, mixed> $data
     */
    private function isStale(array $data, string $type, mixed $version): bool
    {
        if (! is_int($version)) {
            return false;
        }

        // A version is only comparable within ONE resource (one invoice, one
        // subscription, one customer): key it by that resource's id.
        $resourceId = $data['subscription_id'] ?? $data['invoice_id'] ?? $data['customer_id'] ?? null;

        if (! is_string($resourceId)) {
            return false;
        }

        $key = 'core:version:'.explode('.', $type)[0].':'.$resourceId;
        $known = (int) Cache::get($key, 0);

        if ($version < $known) {
            return true;
        }

        Cache::put($key, $version, now()->addDays(30));

        return false;
    }
}
