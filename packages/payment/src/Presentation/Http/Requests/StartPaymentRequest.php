<?php

declare(strict_types=1);

namespace Core\Payment\Presentation\Http\Requests;

use Core\Payment\PaymentFlow;
use Core\Support\Integration\Security\SafeOutboundUrlRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates `POST /v1/payments`. The `payments:write` scope is enforced
 * in the controller against the calling Project — this class only
 * validates payload shape.
 *
 * Two headers sit above this body and are documented separately by
 * Scramble: `Authorization` (Project API key) and `Idempotency-Key`
 * (required on every monetary mutation — replay the same key to get
 * the cached response without re-charging).
 */
final class StartPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /**
             * The channel the customer will pay through. The Core
             * routes the call to whichever payment provider is
             * configured for this channel on the calling Project —
             * the caller never names a provider directly.
             *
             * @example "mtn_momo"
             */
            'channel' => ['required', 'string', Rule::in(['mtn_momo', 'orange_money', 'card'])],
            /**
             * The amount to charge, expressed in the smallest
             * indivisible unit of the currency — cents for USD/EUR,
             * centimes for XAF. The decimal-to-minor conversion is the
             * caller's responsibility. `100000` means 1000.00 in the
             * currency's major unit.
             *
             * @example 100000
             */
            'amount' => ['required', 'integer', 'min:1'],
            /**
             * The ISO 4217 three-letter code of the currency. The
             * provider configured for the chosen `channel` must
             * support it — otherwise the request fails with a
             * 422 `unsupported-currency` error before reaching the
             * payment network.
             *
             * @example "XAF"
             */
            'currency' => ['required', 'string', 'size:3'],
            /**
             * A short label for the payment, shown to the customer on
             * the hosted page and forwarded to the provider as the
             * transaction description. Max 255 characters. Not echoed
             * back in the API response — keep your own copy if you
             * need it later.
             *
             * @example "Order #1234 — Premium plan"
             */
            'reason' => ['required', 'string', 'max:255'],
            /**
             * The customer's phone number in international format
             * (E.164, e.g. `+2376XXXXXXXX`). Forwarded to the
             * provider so USSD prompts and OTP codes reach the right
             * line on mobile-money channels. Optional — providers that
             * need it for their flow will reject the request if it's
             * missing.
             *
             * @example "+237690000000"
             */
            'customer_phone_number' => ['nullable', 'string', 'max:32'],
            /**
             * The customer's display name. Optional. Forwarded to the
             * provider and rendered on the hosted payment page where
             * the provider supports it.
             *
             * @example "Marie Dupont"
             */
            'customer_name' => ['nullable', 'string', 'max:255'],
            /**
             * The customer's email. Optional. Forwarded to the
             * provider for receipt delivery where supported. Not
             * persisted by the Core — the Core never holds customer
             * PII; whatever the provider does with it is the
             * provider's responsibility.
             *
             * @example "marie.dupont@example.com"
             */
            'customer_email' => ['nullable', 'email'],
            /**
             * A BCP-47 language tag for the customer's locale
             * (`fr`, `en`, `fr-CM`...). Forwarded to the provider so
             * the hosted payment page is rendered in the right
             * language. Optional — providers fall back to their
             * default when missing.
             *
             * @example "fr-CM"
             */
            'customer_lang' => ['nullable', 'string', 'max:8'],
            /**
             * How the payment starts. `payin` is the synchronous,
             * app-driven flow: the Core returns immediately with a
             * transaction reference and the caller drives the
             * customer through their own UX. `paylink` is the
             * hosted-link flow: the Core returns a URL and the caller
             * redirects the customer there. If omitted, the
             * provider default applies (typically `payin`).
             *
             * @example "payin"
             */
            'flow' => ['nullable', Rule::enum(PaymentFlow::class)],
            /**
             * Where to send the customer's browser after they
             * complete (or cancel) the payment on the
             * provider-hosted page. Best-effort: this is
             * browser-side only and may never fire (closed tab,
             * disabled JS, captive portal). For a reliable
             * server-to-server signal, use `callback_url` instead.
             *
             * @example "https://my-app.example.com/payments/return"
             */
            'return_url' => ['nullable', 'url'],
            /**
             * Optional. The external reference of the wallet to
             * credit automatically when this payment reaches a
             * successful terminal state. When present, the credit
             * happens in the same database transaction as the
             * payment confirmation — no separate API call needed,
             * no window for race conditions. When absent, the
             * payment is a pure collection: the money is held by the
             * Core but no wallet is credited. The value must match a
             * wallet owned by the calling Project; the request fails
             * with a 422 `wallet-project-mismatch` otherwise.
             *
             * @example "wallet-customer-42"
             */
            'wallet_recipient_external_ref' => ['nullable', 'string', 'max:255'],
            /**
             * Where the Core POSTs the server-to-server notification
             * when the payment reaches a terminal state. This is the
             * reliable channel — use it to update your own order
             * state, release stock, send a confirmation email, etc.
             * The body is JSON and signed with HMAC-SHA256 (headers
             * `X-Signature`, `X-Timestamp`); the secret is returned
             * in the initiation response and never again, so store
             * it at registration time. Distinct from `return_url`:
             * this is server-to-server, `return_url` is browser-side.
             *
             * @example "https://my-app.example.com/webhooks/core"
             */
            'callback_url' => ['nullable', 'url', 'starts_with:http://,https://', new SafeOutboundUrlRule],
        ];
    }
}
