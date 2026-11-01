<?php

declare(strict_types=1);

namespace Core\Payment\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The query of `GET /v1/payments` (ADR-0088 §2).
 */
final class ListPaymentsRequest extends FormRequest
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
             * Payments per page, from 1 to 100 (50 by default).
             *
             * @example 50
             */
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            /**
             * Only the payments in this status.
             *
             * @example succeeded
             */
            'status' => ['nullable', 'string', 'in:pending,processing,succeeded,failed,expired,refunded,canceled'],
            /**
             * Only the payments created at or after this instant (ISO 8601).
             *
             * @example 2026-10-01T00:00:00Z
             */
            'created_from' => ['nullable', 'date'],
            /**
             * Only the payments created strictly before this instant (ISO 8601).
             *
             * @example 2026-11-01T00:00:00Z
             */
            'created_to' => ['nullable', 'date'],
        ];
    }
}
