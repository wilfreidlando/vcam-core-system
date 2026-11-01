<?php

declare(strict_types=1);

namespace Core\Billing\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /v1/invoices/{id}/void` — ADR-0073 §3, the cancellation of an
 * invoice that has not been paid.
 */
final class VoidInvoiceRequest extends FormRequest
{
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
             * The motive of the cancellation — mandatory: a cancellation
             * is an accounting act, and an accounting act leaves a trace.
             *
             * @example "Erreur de facturation"
             */
            'reason' => ['required', 'string', 'max:500', 'not_regex:/[\x00-\x1F\x7F]/'],
        ];
    }
}
