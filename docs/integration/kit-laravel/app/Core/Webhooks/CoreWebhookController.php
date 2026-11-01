<?php

declare(strict_types=1);

namespace App\Core\Webhooks;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * The single endpoint for everything the Core tells you (payments, wallets,
 * notifications, subscriptions…): verified, de-duplicated, then handed to YOUR
 * listeners as a `CoreEventReceived` event. It decides nothing about your business.
 *
 *   Route::post('/webhooks/core', CoreWebhookController::class);   // outside the `web` group: no CSRF, no session
 *
 * Answers: `204` handled (or already handled) · `401` bad signature (the only
 * 4xx you should ever send — a 4xx makes the Core give up) · anything thrown by
 * a listener becomes a `500`, so the Core retries later.
 */
final class CoreWebhookController
{
    private const TOLERANCE_SECONDS = 300;

    public function __invoke(Request $request): Response
    {
        $raw = $request->getContent();   // the body as received, BEFORE any decoding

        // `X-Signature-Previous` is sent during a secret rotation (the old secret still signs for a while).
        $signatures = array_filter([(string) $request->header('X-Signature'), (string) $request->header('X-Signature-Previous')]);

        if (! self::verify($raw, (string) $request->header('X-Timestamp'), $signatures, (string) config('core.webhook_secret'))) {
            return response('invalid signature', 401);
        }

        $event = json_decode($raw, true);

        if (! is_array($event) || ! is_string($event['id'] ?? null) || ! is_string($event['type'] ?? null)) {
            return response('', 204);
        }

        // At-least-once delivery: process each id once — but mark it done only
        // AFTER the listeners ran, so a failure is retried instead of swallowed.
        $seen = 'core:event:'.$event['id'];

        if (Cache::has($seen)) {
            return response('', 204);
        }

        event(new CoreEventReceived($event['id'], $event['type'], is_array($event['data'] ?? null) ? $event['data'] : []));

        Cache::put($seen, 1, now()->addDays(2));

        return response('', 204);
    }

    /**
     * HMAC-SHA256 of `timestamp.body` with your secret, constant-time compared.
     * Public so a `callback_url` endpoint can verify with its `pcbsec_…` secret.
     *
     * @param list<string> $signatures
     */
    public static function verify(string $raw, string $timestamp, array $signatures, string $secret): bool
    {
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
}
