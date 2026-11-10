<?php

declare(strict_types=1);

namespace Core\Billing\Presentation\Http\Requests;

use Core\Billing\Presentation\Http\Requests\Concerns\CastsNumericInput;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /v1/subscriptions/{id}/supplements` — ADR-0117 §4, the purchase
 * of units added on top of the limit of a metered feature.
 *
 * The **meter** is the name the project gave its usage (ADR-0071 §3):
 * it is format-checked here so it can never carry anything else than a
 * meter name.
 *
 * The amounts are in **minor units** (G5) and the quantity is a count of
 * units, never a float.
 */
final class PurchaseSubscriptionSupplementRequest extends FormRequest
{
    use CastsNumericInput;

    public function authorize(): bool
    {
        // The scope is checked by the controller (`subscriptions:write`):
        // the request is valid on its own terms, the caller is the one
        // that may or may not use it.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /**
             * The metered feature the supplement extends — the meter's
             * name of the project.
             *
             * @example "whatsapp_messages"
             */
            'meter' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9_.]{1,64}$/'],

            /**
             * How many units are added to the limit — at least one.
             *
             * @example 50
             */
            'quantity' => ['required', 'integer:strict', 'min:1', 'max:1000000'],

            /**
             * The price of one unit, in minor units of the currency of
             * the contract.
             *
             * Strictly positive (ADR-0121) and capped by the same bound
             * as the catalogue (ADR-0119 §2) : the product
             * quantity × price must stay inside the 64-bit integer, or
             * the invoice line overflows and the call ends in a 500
             * instead of a 422.
             *
             * @example 5000
             */
            'unit_price_minor' => ['required', 'integer:strict', 'min:1', 'max:100000000'],

            /**
             * The wording of the invoice line — the Core writes one when
             * the caller gives none.
             *
             * @example "Pack de 50 messages WhatsApp"
             */
            'description' => ['nullable', 'string', 'max:500', 'not_regex:/[\x00-\x1F\x7F]/'],
        ];
    }

    /**
     * F-12 — `"5000"` devient `5000` avant la validation ; une valeur non
     * numérique reste telle quelle et la règle la refuse en 422 (jamais de
     * `TypeError` en 500).
     */
    protected function prepareForValidation(): void
    {
        $this->merge(self::castNumericKeys($this->inputAsArray(), ['quantity', 'unit_price_minor']));
    }
}
