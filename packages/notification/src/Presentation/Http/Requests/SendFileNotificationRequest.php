<?php

declare(strict_types=1);

namespace Core\Notification\Presentation\Http\Requests;

use Core\Support\Integration\Security\SafeOutboundUrlRule;
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
             * @example "https://www.w3.org/WAI/ER/tests/xhtml/testfiles/resources/pdf/dummy.pdf"
             */
            'media_url' => ['required', 'string', 'max:2048', 'url', 'starts_with:http://,https://', new SafeOutboundUrlRule],
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
             * Free-form map of provider-specific variables forwarded
             * as-is to the channel's adapter. See
             * `POST /v1/notifications/text` for the full contract.
             * For `whatsapp` via WAHA, REQUIRED key `phone_number`.
             *
             * @example {"phone_number": "+2376XXXXXXXX"}
             */
            'variables' => ['nullable', 'array'],
            /**
             * When `channel = "whatsapp"`, the WAHA adapter rejects
             * outbound messages without `variables.phone_number`.
             *
             * @example "+2376XXXXXXXX"
             */
            'variables.phone_number' => ['required_if:channel,whatsapp', 'string', 'min:1'],
            /**
             * Optional opaque reference supplied by the caller.
             * Two requests with the same `client_reference` on the
             * same Project are deduplicated: the second one
             * returns the first one's notification, no second
             * send happens. Use this when you have your own retry
             * loop and want a safe re-play after a timeout. When
             * omitted, the Core generates a fresh UUID per request
             * and every send is treated as a fresh effect.
             *
             * @example "client-msg-abc123"
             */
            'client_reference' => ['nullable', 'string', 'max:255'],
        ];
    }
}
