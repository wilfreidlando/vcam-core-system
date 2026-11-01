<?php

declare(strict_types=1);

namespace Core\Billing\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /v1/customers/{externalRef}/erase` — ADR-0083 §3.
 *
 * The erasure destroys the **identity** of the customer — the name and
 * the contact — and keeps the row: the invoices reference it.
 */
final class EraseCustomerRequest extends FormRequest
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
             * Why the identity of the customer is destroyed. It is kept
             * in the audit trail of the erasure — it explains the
             * decision, it never carries personal data.
             *
             * @example "Request of the customer, 2026-10-27"
             */
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
