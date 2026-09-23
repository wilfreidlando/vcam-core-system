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
             * The channel the file will be sent through. See
             * `POST /v1/notifications/text` for the full
             * description; the rule is the same across all three
             * content types.
             *
             * @example "whatsapp"
             */
            'channel' => ['required', 'string', Rule::in(['whatsapp'])],
            /**
             * Opaque recipient reference in YOUR system. Same
             * semantics as `to_party_ref` on
             * `POST /v1/notifications/text`.
             *
             * @example "customer-42"
             */
            'to_party_ref' => ['required', 'string', 'max:255'],
            /**
             * Publicly reachable HTTP(S) URL of the file. The
             * provider fetches it server-side and re-hosts it.
             * Must be reachable from the provider's outbound
             * network (no private IPs, no localhost) — same
             * constraint as `POST /v1/webhook-endpoints`.
             *
             * @example "https://cdn.example.com/invoice-1234.pdf"
             */
            'media_url' => ['required', 'string', 'max:2048'],
            /**
             * The filename the recipient sees and the
             * content-disposition header is built from. The file's
             * actual content is fetched from `media_url`.
             *
             * @example "invoice-1234.pdf"
             */
            'filename' => ['required', 'string', 'max:255'],
            /**
             * The MIME type of the file
             * (`application/pdf`, `image/png`,
             * `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`...).
             * Required because file extensions are not reliable
             * signals — the provider uses this to set
             * `Content-Type` when re-uploading.
             *
             * @example "application/pdf"
             */
            'mimetype' => ['required', 'string', 'max:255'],
            /**
             * Optional text rendered above the file in the
             * recipient's client (most chat platforms support a
             * short text + file combo). UTF-8. Provider length
             * limits apply.
             *
             * @example "Your invoice for order #1234"
             */
            'caption' => ['nullable', 'string'],
            /**
             * Free-form data the provider needs to actually
             * deliver the message. Shape is provider-specific —
             * for WhatsApp via WAHA, the recipient phone number
             * travels here as `variables.phone_number`.
             *
             * @example ["+237690000000"]
             */
            'variables' => ['nullable', 'array'],
        ];
    }
}
