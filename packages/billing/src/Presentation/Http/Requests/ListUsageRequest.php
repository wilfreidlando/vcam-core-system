<?php

declare(strict_types=1);

namespace Core\Billing\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The query of `GET /v1/usage` (ADR-0088 §2).
 */
final class ListUsageRequest extends FormRequest
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
             * Opaque cursor: the `next_cursor` of the previous page, absent on the first.
             */
            'cursor' => ['nullable', 'string', 'max:512'],
            /**
             * Lines per page, from 1 to 100 (50 by default).
             *
             * @example 50
             */
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            /**
             * Only this meter.
             *
             * @example students
             */
            'meter' => ['nullable', 'string', 'regex:/^[a-z0-9_.]{1,64}$/'],
            /**
             * Only the usage attributed to this customer.
             *
             * @example AE0001
             */
            'customer_ref' => ['nullable', 'string', 'max:128', 'regex:/^[A-Za-z0-9._:-]+$/'],
            /**
             * Only the lines recorded at or after this instant (ISO 8601).
             *
             * @example 2026-10-01T00:00:00Z
             */
            'created_from' => ['nullable', 'date'],
            /**
             * Only the lines recorded strictly before this instant (ISO 8601).
             *
             * @example 2026-11-01T00:00:00Z
             */
            'created_to' => ['nullable', 'date'],
        ];
    }
}
