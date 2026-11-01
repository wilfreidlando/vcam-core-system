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
            /**
             * The https origins (`https://cdn.mon-site.cm`, no path) your
             * `logo_url` and `support_url` may point to. Empty or omitted
             * on a project that never set it = no external URL accepted;
             * omitted on a project that did = the list is kept.
             *
             * @example ["https://cdn.mon-auto-ecole.cm"]
             */
            'allowed_asset_origins' => ['nullable', 'array', 'max:20'],
            'allowed_asset_origins.*' => ['string', 'max:255'],
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
            /**
             * Offer your customers a login by code on the portal page
             * `/portal/{project_id}/login` (off by default). The code is sent on
             * WhatsApp from `direct_login_session`, which is required to enable it.
             */
            'direct_login_enabled' => ['nullable', 'boolean'],
            /** The name of one of your WhatsApp sessions (the one you put in `variables.session`). */
            'direct_login_session' => ['nullable', 'string', 'max:64'],
        ];
    }

    /**
     * Une règle `boolean` valide `"1"` / `"0"` mais ne caste pas : le typage
     * strict des commandes lèverait une `TypeError` (500). On convertit ici,
     * une fois ; toute autre valeur reste intacte et la validation répond 422.
     */
    protected function prepareForValidation(): void
    {
        $casts = [];

        foreach (['allow_self_cancel', 'allow_plan_change', 'direct_login_enabled'] as $field) {
            $value = $this->input($field);

            if ($value === '1' || $value === 1) {
                $casts[$field] = true;
            } elseif ($value === '0' || $value === 0) {
                $casts[$field] = false;
            }
        }

        if ($casts !== []) {
            $this->merge($casts);
        }
    }
}
