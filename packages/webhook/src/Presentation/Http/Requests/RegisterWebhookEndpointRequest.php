<?php

declare(strict_types=1);

namespace Core\Webhook\Presentation\Http\Requests;

use Core\Support\Integration\Security\SafeOutboundUrlRule;
use Illuminate\Foundation\Http\FormRequest;

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
            // `SafeOutboundUrlRule` (security review finding — this used
            // to accept any http(s) URL, including internal/private
            // targets: SSRF, and a persistent one at that, since a
            // registered endpoint is retried and DLQ-replayed).
            'url' => ['required', 'url', 'starts_with:http://,https://', new SafeOutboundUrlRule],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['required', 'string', 'max:100'],
        ];
    }
}
