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
            'channel' => ['required', 'string'],
            'amount' => ['required', 'integer', 'min:1'],
            'currency' => ['required', 'string', 'size:3'],
            'reason' => ['required', 'string', 'max:255'],
            'customer_phone_number' => ['nullable', 'string', 'max:32'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'customer_email' => ['nullable', 'email'],
            'customer_lang' => ['nullable', 'string', 'max:8'],
            'flow' => ['nullable', Rule::enum(PaymentFlow::class)],
            'return_url' => ['nullable', 'url'],
            'wallet_recipient_external_ref' => ['nullable', 'string', 'max:255'],
            // The reliable server-to-server notification target — SSRF
            // validated the same way as POST /v1/webhook-endpoints
            // (security review finding, user-directed expansion).
            'callback_url' => ['nullable', 'url', 'starts_with:http://,https://', new SafeOutboundUrlRule],
        ];
    }
}
