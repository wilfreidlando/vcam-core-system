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
             * Channel the caller asks for — routed by
             * `ConfigNotificationProviderRouter` to whichever provider
             * chain is configured for this Project. Mirrors
             * `Core\Notification\Contracts\NotificationChannel`: a
             * string value (not a closed enum) because the channel set
             * grows by config. The only channel currently routed
             * platform-wide is `whatsapp` (via WAHA, ADR-0042); adding
             * `sms`, `email`, etc. is a config + adapter change, never
             * an endpoint change.
             *
             * @example "whatsapp"
             */
            'channel' => ['required', 'string', Rule::in(['whatsapp'])],
            /**
             * Caller-supplied correlation reference for the recipient
             * — NOT a phone number, NOT an email address. Resolving it
             * to an actual delivery address is the provider adapter's
             * job (ADR-0041 §5). For WhatsApp via WAHA the adapter
             * reads the address from `variables.phone_number` (G4 —
             * never from `to_party_ref`). Typical value: a Project's
             * own user id, an order id, or any opaque token stable
             * per recipient.
             *
             * @example "customer-42"
             */
            'to_party_ref' => ['required', 'string', 'max:255'],
            /**
             * The message body — UTF-8, no length cap enforced here
             * (provider-specific limits apply downstream). Rendered
             * verbatim by the adapter; the Core does not interpolate
             * `variables` into it.
             *
             * @example "Hello Marie, your order #1234 is confirmed."
             */
            'text' => ['required', 'string'],
            /**
             * Adapter-specific variables (e.g.
             * `{phone_number, template_id, locale}` for WhatsApp).
             * Shape is provider-defined; the Core forwards the array
             * as-is and never inspects it.
             *
             * @example ["+237690000000"]
             */
            'variables' => ['nullable', 'array'],
        ];
    }
}
