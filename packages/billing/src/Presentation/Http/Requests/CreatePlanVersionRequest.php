<?php

declare(strict_types=1);

namespace Core\Billing\Presentation\Http\Requests;

use Core\Billing\Presentation\Http\Requests\Concerns\CastsNumericInput;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /v1/plans/{code}/versions` — ADR-0071 §5.
 *
 * A version carries the **terms** of a plan. Once published, it is
 * frozen — the price displayed, the price billed and the price
 * contracted can never drift apart (ADR-0071 §2).
 */
final class CreatePlanVersionRequest extends FormRequest
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
             * The price of the period, in **minor units** of the
             * currency — 500 for 5,00 XAF, never 5.0 (G5).
             *
             * @example 5000
             */
            'price_minor' => ['required', 'integer:strict', 'min:0'],
            /**
             * The currency of the price, as its ISO-4217 code.
             *
             * @example "XAF"
             */
            'currency' => ['required', 'string', 'size:3'],
            /**
             * The unit the period is counted in.
             *
             * @example "month"
             */
            'interval_unit' => ['required', 'string', 'in:day,week,month,year'],
            /**
             * How many units a period lasts — 1 for a monthly plan, 3
             * for a quarterly one.
             *
             * @example 1
             */
            'interval_count' => ['required', 'integer:strict', 'min:1', 'max:365'],
            /**
             * How many days the contract may be used before its first
             * paid period begins.
             *
             * @example 14
             */
            'trial_days' => ['nullable', 'integer:strict', 'min:0', 'max:365'],
            /**
             * How many days the service keeps running after the period
             * ends, before the contract is suspended.
             *
             * @example 3
             */
            'grace_days' => ['nullable', 'integer:strict', 'min:0', 'max:90'],
            /**
             * Whether the service stays reachable during the grace
             * period — read-only when it does not.
             *
             * @example true
             */
            'grace_access' => ['nullable', 'boolean'],
            /**
             * The named features of the version. The names are the
             * **project's** — the Core checks their format, never a
             * list of them (ADR-0071 §3). A feature is never a price.
             *
             * @example {"whatsapp_messages": {"included": 100}}
             */
            'features' => ['nullable', 'array'],
            'features.*' => ['array'],
        ];
    }

    /**
     * F-12 — `"5000"` devient `5000` avant la validation ; une valeur non numérique
     * reste telle quelle et la règle la refuse en 422 (jamais de `TypeError` en 500).
     */
    protected function prepareForValidation(): void
    {
        $cast = self::castNumericKeys($this->inputAsArray(), ['price_minor', 'interval_count', 'trial_days', 'grace_days'], ['grace_access']);

        $this->merge($cast);
    }
}
