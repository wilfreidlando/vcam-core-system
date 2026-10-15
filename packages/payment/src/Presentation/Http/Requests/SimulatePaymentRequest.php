<?php

declare(strict_types=1);

namespace Core\Payment\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates `POST /v1/payments/{paymentId}/simulate` (ADR-0066).
 */
final class SimulatePaymentRequest extends FormRequest
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
             * The outcome to simulate. All four are final: once applied,
             * the payment can no longer change.
             *
             * @example "succeeded"
             */
            'status' => ['required', 'string', Rule::in(['succeeded', 'failed', 'expired', 'canceled'])],
            /**
             * Optional failure reason, returned as `error_message` on the
             * payment and in the notification.
             *
             * @example "Insufficient balance"
             */
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
