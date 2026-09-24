<?php

declare(strict_types=1);

namespace Core\Webhook\Presentation\Http\Requests;

use Core\Support\Integration\Security\SafeOutboundUrlRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates `POST /v1/webhook-endpoints`. The `webhooks:write` scope
 * is enforced in the controller against the calling Project.
 */
final class RegisterWebhookEndpointRequest extends FormRequest
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
             * The public URL the Core POSTs the signed payload to
             * when an event matches. Must be reachable from the
             * Core's outbound network — no localhost, no
             * RFC-1918 ranges, no link-local addresses. The
             * reason is that a registered endpoint is retried
             * automatically for hours, so an address that points
             * at an internal service today could become an
             * unintentional exfiltration channel tomorrow. HTTPS
             * is required in production; HTTP is accepted only
             * for local development.
             *
             * @example "https://example.com/webhooks/core"
             */
            'url' => ['required', 'url', 'starts_with:http://,https://', new SafeOutboundUrlRule],
            /**
             * The list of event types the endpoint wants to
             * receive. At least one entry is required. Use the
             * wildcard `["*"]` to subscribe to every event the
             * Core emits — useful for development, but avoid in
             * production because new event types are added
             * silently to wildcard subscribers and may break a
             * receiver that expected a fixed schema.
             *
             * @example ["payment.status_changed"]
             */
            'events' => ['required', 'array', 'min:1'],
            /**
             * One event type per array element. The currently
             * supported values are listed in the enum of this
             * field in `/docs/api`.
             *
             * @example "payment.status_changed"
             */
            'events.*' => ['required', 'string', Rule::in([
                '*',
                'payment.status_changed',
                'notification.status_changed',
                'notification.failed',
                'wallet.credited',
                'wallet.debited',
            ])],
        ];
    }
}
