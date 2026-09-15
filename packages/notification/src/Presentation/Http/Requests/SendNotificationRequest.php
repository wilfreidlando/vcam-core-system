<?php

declare(strict_types=1);

namespace Core\Notification\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates `POST /v1/notifications` (spec §13's own example — field
 * names match it exactly: `channel`, `to_party_ref`, `template`,
 * `variables`). No `idempotency_key` body field: the `Idempotency-Key`
 * HTTP header (required by the `idempotency` middleware, spec §13 —
 * "toutes les mutations monétaires et d'envoi") is the one value used,
 * mirroring `StartPaymentRequest`.
 */
final class SendNotificationRequest extends FormRequest
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
            'channel' => ['required', 'string'],
            'to_party_ref' => ['required', 'string', 'max:255'],
            'template' => ['required', 'string', 'max:255'],
            'variables' => ['nullable', 'array'],
        ];
    }
}
