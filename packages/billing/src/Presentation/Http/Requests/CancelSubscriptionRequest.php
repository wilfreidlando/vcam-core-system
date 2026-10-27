<?php

declare(strict_types=1);

namespace Core\Billing\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /v1/subscriptions/{subscriptionId}/cancel` — ADR-0072 §7.
 */
final class CancelSubscriptionRequest extends FormRequest
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
             * Whether the contract stops at the end of the period it is
             * in (the default) or immediately. A contract that stops
             * immediately is not refunded by the Core — the invoice of
             * the period stays due.
             *
             * @example true
             */
            'at_period_end' => ['nullable', 'boolean'],
            /**
             * Why the contract is cancelled. It is kept in the trail of
             * the contract; it never carries personal data.
             *
             * @example "The customer moved to another plan"
             */
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
