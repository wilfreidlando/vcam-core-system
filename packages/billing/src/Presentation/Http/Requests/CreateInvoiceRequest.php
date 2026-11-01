<?php

declare(strict_types=1);

namespace Core\Billing\Presentation\Http\Requests;

use Core\Billing\Presentation\Http\Requests\Concerns\CastsNumericInput;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /v1/invoices` — ADR-0073 §1, a manual invoice.
 *
 * The customer is named by the reference the project knows it by (the
 * correlation key of ADR-0070 §1): the reference is format-checked so it
 * can never be injected into a URL, a log or a cache key.
 */
final class CreateInvoiceRequest extends FormRequest
{
    use CastsNumericInput;

    public function authorize(): bool
    {
        // The scope is checked by the controller (`invoices:write`): the
        // request is valid on its own terms, the caller is the one that
        // may or may not use it.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /**
             * The reference the project knows this customer by.
             *
             * @example "acme-42"
             */
            'customer_ref' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9._:-]{1,128}$/'],

            /**
             * The currency of the amounts — the default of the project
             * when omitted.
             *
             * @example "XAF"
             */
            'currency' => ['nullable', 'string', 'size:3'],

            /**
             * The tax rate of the lines that name none, in basis points
             * — the default of the project when omitted.
             *
             * @example 1925
             */
            'tax_rate_bps' => ['nullable', 'integer:strict', 'min:0', 'max:10000'],

            /**
             * The instant the invoice falls due — optional: an invoice
             * without a due date is payable as soon as it is received.
             *
             * @example "2026-12-01T00:00:00Z"
             */
            'due_at' => ['nullable', 'string', 'date'],

            /**
             * The lines of the invoice — at least one.
             *
             * @example [["description" => "Mise en service", "quantity" => 1, "unit_amount_minor" => 50000]]
             */
            'lines' => ['required', 'array', 'min:1', 'max:100'],

            /**
             * What the line is.
             *
             * @example "Mise en service"
             */
            'lines.*.description' => ['required', 'string', 'max:500', 'not_regex:/[\x00-\x1F\x7F]/'],

            /**
             * How many times the line counts.
             *
             * @example 2
             */
            'lines.*.quantity' => ['required', 'integer:strict', 'min:1', 'max:1000000'],

            /**
             * The price of one unit, in minor units.
             *
             * @example 50000
             */
            'lines.*.unit_amount_minor' => ['required', 'integer:strict', 'min:0'],

            /**
             * The tax rate of this line, in basis points — the default
             * of the project when omitted.
             *
             * @example 1925
             */
            'lines.*.tax_rate_bps' => ['nullable', 'integer:strict', 'min:0', 'max:10000'],
        ];
    }

    /**
     * F-12 — `"5000"` devient `5000` avant la validation ; une valeur non numérique
     * reste telle quelle et la règle la refuse en 422 (jamais de `TypeError` en 500).
     */
    protected function prepareForValidation(): void
    {
        $cast = self::castNumericKeys($this->inputAsArray(), ['tax_rate_bps']);

        $rawLines = $this->input('lines');
        if (is_array($rawLines)) {
            $cast['lines'] = array_map(
                static fn (mixed $line): mixed => is_array($line) ? array_merge($line, self::castNumericKeys($line, ['quantity', 'unit_amount_minor', 'tax_rate_bps'])) : $line,
                $rawLines,
            );
        }

        $this->merge($cast);
    }
}
