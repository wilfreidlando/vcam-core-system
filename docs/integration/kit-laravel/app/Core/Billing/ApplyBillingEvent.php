<?php

declare(strict_types=1);

namespace App\Billing;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * Receives the Core's events (ADR-0084 §3): verified, deduplicated, ordered.
 *
 * Add the route OUTSIDE the `web` group (no CSRF, no session):
 *   Route::post('/webhooks/core', CoreWebhookController::class);
 */
final class CoreWebhookController
{
    private const TOLERANCE_SECONDS = 300;

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

    public function __invoke(Request $request, CoreBilling $billing): Response
    {
        $raw = $request->getContent();   // the body as received, BEFORE any decoding

        $signatures = array_filter([(string) $request->header('X-Signature'), (string) $request->header('X-Signature-Previous')]);

        if (! $this->signatureIsValid($raw, (string) $request->header('X-Timestamp'), $signatures)) {
            return response('invalid signature', 401);       // the only 4xx you should send
        }

        $event = json_decode($raw, true);

        if (! is_array($event) || ! isset($event['id'], $event['type'])) {
            return response('', 204);
        }

        // At-least-once delivery: process each event id once — but mark it
        // done only AFTER it was applied, so a failure is retried by the Core
        // instead of being swallowed as a duplicate.
        $seen = 'core_billing:event:'.$event['id'];

        if (Cache::has($seen)) {
            return response('', 204);
        }

        $data = is_array($event['data'] ?? null) ? $event['data'] : [];
        $ref = $data['customer_ref'] ?? $data['external_ref'] ?? null;

        if (is_string($ref)) {
            $billing->forget($ref);                          // next read is fresh

            // Delivery order is not guaranteed: ignore an event older than
            // the last one applied for this tenant (`version` of the resource).
            if ($this->isStale($data, $event['type'], $data['version'] ?? null)) {
                return response('', 204);
            }

            if (isset(self::EFFECTS[$event['type']])) {
                [$state, $reason] = self::EFFECTS[$event['type']];

                // « Do not renew » is announced as `subscription.canceled` with
                // `cancel_at_period_end = true`: the tenant keeps its rights until
                // the period ends (then `subscription.expired` / a final
                // `subscription.canceled` tells you). Never block on the request.
                if ($event['type'] === 'subscription.canceled' && ($data['cancel_at_period_end'] ?? false) === true) {
                    [$state, $reason] = ['warning', 'subscription will not renew'];
                }

                TenantAccess::apply($ref, $state, $reason);
            }

            // usage.threshold_reached / usage.limit_reached: warn your tenant
            // here (banner, e-mail…) — `$data['meter']`, `$data['used']`, `$data['limit']`.
        }

        Cache::put($seen, 1, now()->addDays(2));

        return response('', 204);
    }

    /**
     * `X-Signature-Previous` is sent during a secret rotation (the old secret
     * still signs for a while): either signature may match.
     *
     * @param list<string> $signatures
     */
    private function signatureIsValid(string $raw, string $timestamp, array $signatures): bool
    {
        $secret = (string) config('core_billing.webhook_secret');

        // Fail closed: an unset secret would let anyone compute a valid HMAC.
        if ($secret === '' || ! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > self::TOLERANCE_SECONDS) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$raw, $secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
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

        $key = 'core_billing:version:'.explode('.', $type)[0].':'.$resourceId;
        $known = (int) Cache::get($key, 0);

        if ($version < $known) {
            return true;
        }

        Cache::put($key, $version, now()->addDays(30));

        return false;
    }
}
