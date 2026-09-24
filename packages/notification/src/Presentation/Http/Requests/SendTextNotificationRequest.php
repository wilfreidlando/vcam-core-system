<?php

declare(strict_types=1);

namespace Core\Notification\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SendTextNotificationRequest extends FormRequest
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
             * The channel the message will be sent through. The Core
             * picks the right provider based on this value and the
             * calling Project's configuration. Adding a new channel
             * is a configuration change, never a code change —
             * check `/docs/api` for the current list.
             *
             * @example "whatsapp"
             */
            'channel' => ['required', 'string', Rule::in(['whatsapp'])],
            /**
             * An opaque reference that identifies the recipient in
             * YOUR system — a user id, an order id, a customer
             * record id. NOT a phone number and NOT an email
             * address: the Core does not know how to reach the
             * recipient from this value alone. How it gets mapped to
             * a real delivery address depends on the channel and
             * provider — typically by reading a field like
             * `variables.phone_number` you include below.
             *
             * @example "customer-42"
             */
            'to_party_ref' => ['required', 'string', 'max:255'],
            /**
             * The message body, UTF-8. Rendered verbatim by the
             * provider — the Core does not interpolate
             * `variables` into it. Provider-specific length limits
             * apply downstream (check the channel's adapter
             * documentation for the cap on the platform you target).
             *
             * @example "Hello Marie, your order #1234 is confirmed."
             */
            'text' => ['required', 'string'],
            /**
             * Free-form map of provider-specific variables forwarded
             * as-is to the channel's adapter. The keys required depend
             * on the channel:
             *
             * - `whatsapp` (via WAHA, ADR-0042): REQUIRED key
             *   `phone_number` with the recipient's international
             *   phone number (E.164, e.g. `+237690000000`). Without
             *   it the cascade fails asynchronously and the
             *   notification ends up in `failed` — the only way for
             *   the caller to know is to poll `GET /v1/notifications/{id}`.
             *
             * The Core never inspects this map's contents; see the
             * channel's adapter (`packages/notification/src/Infrastructure/Provider`)
             * for the full per-channel key list.
             *
             * @example {"phone_number": "+237690000000"}
             */
            'variables' => ['nullable', 'array'],
            /**
             * When `channel = "whatsapp"`, the WAHA adapter rejects
             * outbound messages without `variables.phone_number`.
             * Validating here means a missing field produces a
             * clean 422 instead of an asynchronous cascade failure
             * the caller would only learn about by polling.
             *
             * @example "+237690000000"
             */
            'variables.phone_number' => ['required_if:channel,whatsapp', 'string', 'min:1'],
            /**
             * Optional opaque reference supplied by the caller. Two
             * requests with the same `client_reference` on the same
             * Project are deduplicated: the second one returns the
             * first one's notification, no second send happens. Use
             * this when you have your own retry loop and want a
             * safe re-play after a timeout — the same key on a
             * re-play means "this is the same notification I tried
             * to send before, please don't double-send". When
             * omitted, the Core generates a fresh UUID per request
             * and every send is treated as a fresh effect (no
             * caller-side deduplication is offered).
             *
             * @example "client-msg-abc123"
             */
            'client_reference' => ['nullable', 'string', 'max:255'],
        ];
    }
}
