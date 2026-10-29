<?php

declare(strict_types=1);

namespace Core\Billing\Presentation\Http\Requests;

use Core\Billing\Presentation\Http\Requests\Concerns\CastsNumericInput;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /v1/subscriptions` — ADR-0072 §7.
 *
 * Signing a contract twice on a replayed request is a real harm, not a
 * duplicate row: the route carries the `idempotency` middleware (G7).
 */
final class CreateSubscriptionRequest extends FormRequest
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
             * The reference the project knows the customer by — the same
             * reference the customer was declared with.
             *
             * @example "CUST-00042"
             */
            'customer_ref' => ['required', 'string', 'max:128'],
            /**
             * The code of the plan the contract is signed on. The
             * contract then **freezes** the terms of the version that
             * was published at that moment (ADR-0071 §Conséquences).
             *
             * @example "mensuel"
             */
            'plan_code' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9_-]{0,63}$/'],
            /**
             * How many days the contract may be used before its first
             * paid period begins. It overrides the trial of the plan
             * version when it is set.
             *
             * @example 14
             */
            'trial_days' => ['nullable', 'integer:strict', 'min:0', 'max:365'],
            /**
             * When the contract starts. Defaults to now.
             *
             * @example "2026-11-01T00:00:00Z"
             */
            'start_at' => ['nullable', 'string', 'date'],
        ];
    }

    /**
     * F-12 — `"5000"` devient `5000` avant la validation ; une valeur non numérique
     * reste telle quelle et la règle la refuse en 422 (jamais de `TypeError` en 500).
     */
    protected function prepareForValidation(): void
    {
        $cast = self::castNumericKeys($this->inputAsArray(), ['trial_days']);

        $this->merge($cast);
    }
}
