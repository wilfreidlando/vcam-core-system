<?php

declare(strict_types=1);

namespace Core\Notification\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The query of `GET /v1/notifications` (ADR-0088 §2).
 */
final class ListNotificationsRequest extends FormRequest
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
             * Notifications per page, from 1 to 100 (50 by default).
             *
             * @example 50
             */
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            /**
             * Only the notifications in this status.
             *
             * @example delivered
             */
            'status' => ['nullable', 'string', 'in:queued,sent,delivered,read,failed'],
            /**
             * Only the notifications sent through this channel.
             *
             * @example whatsapp
             */
            'channel' => ['nullable', 'string', 'regex:/^[a-z][a-z0-9_]*$/', 'max:64'],
            /**
             * Only the notifications created at or after this instant (ISO 8601).
             *
             * @example 2026-10-01T00:00:00Z
             */
            'created_from' => ['nullable', 'date'],
            /**
             * Only the notifications created strictly before this instant (ISO 8601).
             *
             * @example 2026-11-01T00:00:00Z
             */
            'created_to' => ['nullable', 'date'],
        ];
    }
}
