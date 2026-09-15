<?php

declare(strict_types=1);

namespace Core\Webhook\Presentation\Http\Requests;

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
            'url' => ['required', 'url', 'starts_with:http://,https://'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['required', 'string', 'max:100'],
        ];
    }
}
