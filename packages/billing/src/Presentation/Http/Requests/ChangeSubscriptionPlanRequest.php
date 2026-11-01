<?php

declare(strict_types=1);

namespace Core\Billing\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /v1/subscriptions/{subscriptionId}/change-plan` — ADR-0072 §7.
 */
final class ChangeSubscriptionPlanRequest extends FormRequest
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
             * The code of the plan the contract moves to. The new terms
             * are those of the version published at the moment of the
             * change; the contract then freezes them (ADR-0071
             * §Conséquences).
             *
             * @example "annuel"
             */
            'plan_code' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9_-]{0,63}$/'],
            /**
             * `period_end` moves the contract to the new plan when the
             * period it is in ends (the default); `immediate` moves it
             * at once.
             *
             * @example "period_end"
             */
            'effective' => ['nullable', 'string', 'in:period_end,immediate'],
        ];
    }
}
