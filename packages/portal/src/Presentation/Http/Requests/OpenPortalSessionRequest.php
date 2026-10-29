<?php

declare(strict_types=1);

namespace Core\Portal\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /v1/portal/sessions` — ADR-0075 §1.
 */
final class OpenPortalSessionRequest extends FormRequest
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
             * The customer, by the reference your project knows it by.
             *
             * @example "AE0001"
             */
            'customer_ref' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9._:-]+$/'],
            /**
             * Where the customer lands: `manage` (the home of the portal,
             * default) or `pay_invoice` (straight to an invoice, which needs
             * `invoice_id`).
             *
             * @example "manage"
             */
            'flow' => ['nullable', 'string', 'in:manage,pay_invoice'],
            /**
             * The invoice to open with the `pay_invoice` flow.
             */
            'invoice_id' => ['nullable', 'uuid'],
            /**
             * Where the portal's "Retour" button sends the customer. It must
             * be one of the origins you allowed in `PUT /v1/portal/settings`,
             * otherwise the call is refused (422).
             *
             * @example "https://app.mon-auto-ecole.cm/compte"
             */
            'return_url' => ['nullable', 'string', 'max:2048'],
            /**
             * Optional: declare or refresh the customer in the same call, so
             * your project needs no separate "sync customers" job. Needs the
             * `customers:write` scope. Omitted fields keep their value.
             */
            'customer' => ['nullable', 'array'],
            'customer.display_name' => ['nullable', 'string', 'max:200'],
            'customer.email' => ['nullable', 'email', 'max:254'],
            'customer.phone' => ['nullable', 'string', 'max:32'],
            'customer.locale' => ['nullable', 'string', 'max:10'],
            'customer.metadata' => ['nullable', 'array'],
            /**
             * Optional: subscribe the customer to this plan **if it has no
             * live contract on it yet** — so the call is safe on every login.
             * Needs the `subscriptions:write` scope.
             *
             * @example "pro"
             */
            'plan_code' => ['nullable', 'string', 'regex:/^[a-z0-9][a-z0-9_-]{0,63}$/'],
            /**
             * Free trial days when the subscription is created by this call.
             *
             * @example 14
             */
            'trial_days' => ['nullable', 'integer', 'between:0,365'],
        ];
    }

    /**
     * Une règle `integer` valide `"14"` mais ne caste pas : sans conversion,
     * `trial_days` tombait à `null` et l'abonnement était créé SANS essai, sans
     * la moindre erreur. On convertit ici un entier écrit en chaîne ; toute
     * autre valeur (`"abc"`, `14.5`) reste intacte et la validation répond 422.
     */
    protected function prepareForValidation(): void
    {
        $days = $this->input('trial_days');

        if (is_string($days) && preg_match('/^-?[0-9]{1,6}$/', $days) === 1) {
            $this->merge(['trial_days' => (int) $days]);
        }
    }
}
