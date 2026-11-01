<?php

declare(strict_types=1);

namespace Core\Billing\Presentation\Http\Requests;

use Core\Billing\Presentation\Http\Requests\Concerns\CastsNumericInput;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /v1/subscriptions/{subscriptionId}/cancel` — ADR-0072 §7.
 */
final class CancelSubscriptionRequest extends FormRequest
{
    use CastsNumericInput;

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
             * Whether the contract stops at the end of the period it is
             * in (the default) or immediately. A contract that stops
             * immediately is not refunded by the Core — the invoice of
             * the period stays due.
             *
             * @example true
             */
            'at_period_end' => ['nullable', 'boolean'],
            /**
             * Why the contract is cancelled. It is kept in the trail of
             * the contract; it never carries personal data.
             *
             * @example "The customer moved to another plan"
             */
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * F-12 — `"5000"` devient `5000` avant la validation ; une valeur non numérique
     * reste telle quelle et la règle la refuse en 422 (jamais de `TypeError` en 500).
     */
    protected function prepareForValidation(): void
    {
        $cast = self::castNumericKeys($this->inputAsArray(), [], ['at_period_end']);

        $this->merge($cast);
    }
}
