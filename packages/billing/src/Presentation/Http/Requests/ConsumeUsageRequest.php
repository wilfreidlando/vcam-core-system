<?php

declare(strict_types=1);

namespace Core\Billing\Presentation\Http\Requests;

use Core\Billing\Presentation\Http\Requests\Concerns\CastsNumericInput;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /v1/usage` — ADR-0074 §4.
 */
final class ConsumeUsageRequest extends FormRequest
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
             * The customer the usage is attributed to, by the reference the
             * project declared it with. Optional unless the project asked
             * for every metered call to name its customer.
             *
             * @example "AE0001"
             */
            'customer_ref' => ['nullable', 'string', 'max:128', 'regex:/^[A-Za-z0-9._:-]+$/'],
            /**
             * The meter, as the plan names it. The Core keeps no list of
             * meters: the project names them.
             *
             * @example "students"
             */
            'meter' => ['required', 'string', 'regex:/^[a-z0-9_.]{1,64}$/'],
            /**
             * Units spent. Negative releases a gauge (a student leaves) or
             * compensates a failed effect; zero is meaningless.
             *
             * @example 1
             */
            'quantity' => ['required', 'integer:strict', 'not_in:0', 'between:-1000000000,1000000000'],
            /**
             * Makes the call safe to replay: the same key counts once.
             *
             * @example "student:8f2c:enroll"
             */
            'idempotency_key' => ['required', 'string', 'min:1', 'max:255'],
            /**
             * When the usage happened. Defaults to now.
             *
             * @example "2026-11-02T09:12:00Z"
             */
            'occurred_at' => ['nullable', 'date'],
            /**
             * `enforce` refuses beyond the limit (default); `record` states
             * a fact that already happened and is written even beyond it.
             *
             * @example "enforce"
             */
            'mode' => ['nullable', 'in:enforce,record'],
        ];
    }

    /**
     * F-12 — `"5000"` devient `5000` avant la validation ; une valeur non numérique
     * reste telle quelle et la règle la refuse en 422 (jamais de `TypeError` en 500).
     */
    protected function prepareForValidation(): void
    {
        $cast = self::castNumericKeys($this->inputAsArray(), ['quantity']);

        $this->merge($cast);
    }
}
