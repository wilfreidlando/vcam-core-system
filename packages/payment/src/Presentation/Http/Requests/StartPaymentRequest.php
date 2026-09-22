<?php

declare(strict_types=1);

namespace Core\Payment\Presentation\Http\Requests;

use Core\Payment\PaymentFlow;
use Core\Support\Integration\Security\SafeOutboundUrlRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates `POST /v1/payments`. Authorization (the `payments:write`
 * scope) is checked in the controller against `ProjectContext`, not
 * here — this class only validates shape, never identity.
 *
 * No `idempotency_key` body field: the `Idempotency-Key` HTTP header
 * (required by the `idempotency` middleware ahead of this request ever
 * reaching validation — ADR-0025, G7) is the one value used for both
 * the HTTP-level response cache and `StartPaymentUseCase`'s own
 * provider-call protection. One key, not two.
 *
 * `amount` is in minor units (e.g. cents) — never a decimal amount,
 * matching how it is stored (`bigint`, G5).
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
             * Channel the caller asks for — routed by
             * `ConfigPaymentProviderRouter` to whichever provider is
             * configured for this Project. Mirrors
             * `Core\Payment\Contracts\PaymentChannel`: a string value
             * (not a closed enum) because the channel set grows by
             * config, not by Core code change.
             *
             * @example "mtn_momo"
             */
            'channel' => ['required', 'string', Rule::in(['mtn_momo', 'orange_money', 'card'])],
            /**
             * Amount in MINOR units (e.g. cents for USD/EUR, centimes
             * for XAF) — `bigint`, never a decimal (G5). The
             * decimal-to-minor conversion is the caller's
             * responsibility. `100000` here means 1000.00 in the
             * currency's major unit.
             *
             * @example 100000
             */
            'amount' => ['required', 'integer', 'min:1'],
            /**
             * ISO 4217 three-letter currency code. The provider
             * configured for `channel` must support it; otherwise the
             * request fails with 422 `unsupported-currency` before
             * reaching the wire.
             *
             * @example "XAF"
             */
            'currency' => ['required', 'string', 'size:3'],
            /**
             * Free-text label for the payment — shown to the customer
             * on the provider-hosted page (Paylink flow) and forwarded
             * to the provider as `description`. Not user-visible in the
             * API response.
             *
             * @example "Order #1234 — Premium plan"
             */
            'reason' => ['required', 'string', 'max:255'],
            /**
             * Customer phone number in international format
             * (E.164 preferred, e.g. `+2376XXXXXXXX`). Forwarded to the
             * provider so USSD / OTP prompts reach the right line for
             * mobile-money channels. May be required by some
             * providers — check the channel's adapter contract.
             *
             * @example "+237690000000"
             */
            'customer_phone_number' => ['nullable', 'string', 'max:32'],
            /**
             * Customer display name — optional. Forwarded to the
             * provider where supported; rendered on the Paylink page
             * when present.
             *
             * @example "Marie Dupont"
             */
            'customer_name' => ['nullable', 'string', 'max:255'],
            /**
             * Customer email — optional. Forwarded to the provider for
             * receipt delivery where supported. Not used by the Core
             * itself (no PII persisted at this layer — ADR-0033 §4).
             *
             * @example "marie.dupont@example.com"
             */
            'customer_email' => ['nullable', 'email'],
            /**
             * BCP-47 language tag for the customer's locale (e.g.
             * `fr`, `en`, `fr-CM`). Forwarded to the provider so the
             * hosted page is rendered in the customer's language.
             *
             * @example "fr-CM"
             */
            'customer_lang' => ['nullable', 'string', 'max:8'],
            /**
             * Discriminator between the two payment initiation flows
             * (ADR-0038). `payin` is the synchronous, app-driven flow
             * — caller drives the customer through their own UX.
             * `paylink` is the hosted-link, redirect-driven flow —
             * caller hands the customer off to a provider-hosted page.
             * When omitted, the provider default applies (typically
             * `payin`).
             *
             * @example "payin"
             */
            'flow' => ['nullable', Rule::enum(PaymentFlow::class)],
            /**
             * Browser redirect URL after the customer completes (or
             * cancels) the payment on the provider-hosted page.
             * Best-effort: this is browser-side only, never a reliable
             * signal. For the reliable server-to-server notification,
             * use `callback_url` instead.
             *
             * @example "https://my-app.example.com/payments/return"
             */
            'return_url' => ['nullable', 'url'],
            /**
             * External reference of the wallet to credit on payment
             * success (ADR-0037). When present AND the payment reaches
             * a successful terminal state, the wallet is credited
             * synchronously in the same transaction (ADR-0036). When
             * absent, the payment is a pure collection — no wallet,
             * no Party touched. The value must match a wallet owned
             * by the calling Project (ADR-0051) or the call fails
             * with 422 `wallet-project-mismatch`.
             *
             * @example "wallet-customer-42"
             */
            'wallet_recipient_external_ref' => ['nullable', 'string', 'max:255'],
            /**
             * The reliable server-to-server notification target —
             * SSRF validated the same way as POST /v1/webhook-endpoints
             * (security review finding, user-directed expansion,
             * ADR-0051). Delivered signed (HMAC-SHA256, secret
             * returned once at registration) per ADR-0009. Distinct
             * from `return_url` which is browser-side best-effort.
             *
             * @example "https://my-app.example.com/webhooks/core"
             */
            'callback_url' => ['nullable', 'url', 'starts_with:http://,https://', new SafeOutboundUrlRule],
        ];
    }
}
