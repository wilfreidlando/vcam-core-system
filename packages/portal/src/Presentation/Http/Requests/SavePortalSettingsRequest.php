<?php

declare(strict_types=1);

namespace Core\Portal\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `PUT /v1/portal/settings` — ADR-0075 §4.
 */
final class SavePortalSettingsRequest extends FormRequest
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
            /** The name shown at the top of the portal. @example "Mon Auto-École" */
            'display_name' => ['required', 'string', 'max:80'],
            /** An https URL of your logo (shown at most 40px high). */
            'logo_url' => ['nullable', 'string', 'max:500'],
            /** Your brand colour, `#rrggbb`. @example "#0f766e" */
            'primary_color' => ['nullable', 'string', 'max:7'],
            /** An https URL or a `mailto:` address for "Besoin d'aide ?". */
            'support_url' => ['nullable', 'string', 'max:500'],
            /** @example "fr" */
            'locale' => ['nullable', 'string', 'max:5'],
            /**
             * The origins (and optional path prefixes) a `return_url` may
             * point to. Exact match on scheme, host and port.
             */
            'allowed_return_urls' => ['nullable', 'array', 'max:20'],
            'allowed_return_urls.*' => ['string', 'max:500'],
            /** May a customer stop their contract at the end of the period? */
            'allow_self_cancel' => ['nullable', 'boolean'],
            /** May a customer subscribe and change plan alone? */
            'allow_plan_change' => ['nullable', 'boolean'],
            /**
             * Optional. By default the portal offers every payment channel the
             * platform can route for your project (you configure no payment
             * provider). List channels here only to NARROW that offer, e.g.
             * `["mtn_momo"]`. Empty = no restriction.
             *
             * @example ["mtn_momo", "orange_money"]
             */
            'payment_channels' => ['nullable', 'array', 'max:10'],
            'payment_channels.*' => ['string', 'max:32'],
        ];
    }
}
