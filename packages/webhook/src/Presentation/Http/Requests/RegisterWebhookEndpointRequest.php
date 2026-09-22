<?php

declare(strict_types=1);

namespace Core\Webhook\Presentation\Http\Requests;

use Core\Support\Integration\Security\SafeOutboundUrlRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates `POST /v1/webhook-endpoints`. Authorization (the
 * `webhooks:write` scope) is checked in the controller against
 * `ProjectContext`, not here.
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
             * `SafeOutboundUrlRule` (security review finding — this
             * used to accept any http(s) URL, including
             * internal/private targets: SSRF, and a persistent one at
             * that, since a registered endpoint is retried and
             * DLQ-replayed). Publicly reachable, no localhost, no
             * RFC1918 ranges, no link-local. Must be https in
             * production but http is accepted for local development.
             *
             * @example "https://my-app.example.com/webhooks/core"
             */
            'url' => ['required', 'url', 'starts_with:http://,https://', new SafeOutboundUrlRule],
            /**
             * Event types this endpoint subscribes to. `*`
             * (single-element array) means "every event the Core
             * emits" — useful as a debugging crutch, avoid in
             * production (an endpoint subscribed to all events gets
             * every domain change). The currently-emitted event
             * types are listed in the enum on `events.*`; the Core
             * owns the event-type vocabulary, so adding a new event
             * type requires a Core release, not just a config change.
             *
             * @example ["payment.status_changed"]
             */
            'events' => ['required', 'array', 'min:1'],
            /**
             * One event-type name per array element.
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
