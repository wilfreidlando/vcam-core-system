<?php

declare(strict_types=1);

namespace Core\Notification\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SendFileNotificationRequest extends FormRequest
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
             * `ConfigNotificationProviderRouter`. The only channel
             * currently routed platform-wide is `whatsapp` (via WAHA,
             * ADR-0042).
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
             * Publicly reachable HTTP(S) URL of the file to send.
             * Must be `SafeOutboundUrlRule`-clean (no internal/private
             * targets, no SSRF). The adapter fetches it server-side
             * and re-hosts.
             *
             * @example "https://cdn.example.com/invoice-1234.pdf"
             */
            'media_url' => ['required', 'string', 'max:2048'],
            /**
             * Filename to present to the recipient (e.g.
             * `invoice-1234.pdf`). Used for the attachment's display
             * name and content-disposition; the file's actual content
             * is fetched from `media_url`.
             *
             * @example "invoice-1234.pdf"
             */
            'filename' => ['required', 'string', 'max:255'],
            /**
             * MIME type of the file (e.g. `application/pdf`,
             * `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`).
             * Required because file extensions are not reliable
             * signals — the adapter uses this to set `Content-Type`
             * when re-uploading.
             *
             * @example "application/pdf"
             */
            'mimetype' => ['required', 'string', 'max:255'],
            /**
             * Optional caption rendered above the file in the
             * recipient's client (WhatsApp supports a short text +
             * file combo). UTF-8, no length cap at this layer.
             *
             * @example "Your invoice for order #1234"
             */
            'caption' => ['nullable', 'string'],
            /**
             * Adapter-specific variables (e.g. `{phone_number}` for
             * WhatsApp). Shape is provider-defined; the Core forwards
             * the array as-is.
             *
             * @example ["+237690000000"]
             */
            'variables' => ['nullable', 'array'],
        ];
    }
}
