<?php

declare(strict_types=1);

namespace Core\Notification\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SendImageNotificationRequest extends FormRequest
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
             * chain is configured for this Project. The only channel
             * currently routed platform-wide is `whatsapp` (via WAHA,
             * ADR-0042); adding `sms`, `email`, etc. is a config +
             * adapter change, never an endpoint change.
             *
             * @example "whatsapp"
             */
            'channel' => ['required', 'string', Rule::in(['whatsapp'])],
            /**
             * Caller-supplied correlation reference for the recipient
             * — NOT a phone number, NOT an email address. Resolving it
             * to an actual delivery address is the provider adapter's
             * job (ADR-0041 §5). For WhatsApp via WAHA the adapter
             * reads the address from `variables.phone_number` (G4).
             *
             * @example "customer-42"
             */
            'to_party_ref' => ['required', 'string', 'max:255'],
            /**
             * Publicly reachable HTTP(S) URL of the image to send.
             * Must be `SafeOutboundUrlRule`-clean (no internal/private
             * targets, no SSRF — same rule as webhook endpoints). The
             * adapter fetches it server-side and re-hosts on the
             * provider; the caller does not upload binary.
             *
             * @example "https://cdn.example.com/order-1234.png"
             */
            'media_url' => ['required', 'string', 'max:2048'],
            /**
             * Optional caption rendered alongside the image in the
             * recipient's client. UTF-8, no length cap at this layer;
             * provider limits apply downstream.
             *
             * @example "Your order confirmation"
             */
            'caption' => ['nullable', 'string'],
            /**
             * Optional filename hint for the image (used by the
             * adapter when the provider forwards the media as a file
             * attachment).
             *
             * @example "order-1234.png"
             */
            'filename' => ['nullable', 'string', 'max:255'],
            /**
             * Optional MIME type hint (e.g. `image/png`). When
             * omitted, the adapter infers from the URL extension or
             * the upstream `Content-Type`.
             *
             * @example "image/png"
             */
            'mimetype' => ['nullable', 'string', 'max:255'],
            /**
             * Adapter-specific variables (e.g.
             * `{phone_number, caption_locale}` for WhatsApp). Shape
             * is provider-defined; the Core forwards the array as-is.
             *
             * @example ["+237690000000"]
             */
            'variables' => ['nullable', 'array'],
        ];
    }
}
