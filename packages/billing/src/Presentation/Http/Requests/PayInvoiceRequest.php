<?php

declare(strict_types=1);

namespace Core\Billing\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /v1/invoices/{invoiceId}/pay` — ADR-0073 §2, the online
 * collection of an invoice.
 *
 * The amount is **not** in the request: what is due on the invoice is
 * what is collected (ADR-0094 §8). The caller says only how the payer
 * will pay.
 */
final class PayInvoiceRequest extends FormRequest
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
             * The rail the payer will use — the canonical channel of
             * the `payment` module (ADR-0038 §1), never the provider's
             * own name.
             *
             * @example "mtn_momo"
             */
            'channel' => ['required', 'string', Rule::in(['mtn_momo', 'orange_money', 'card'])],

            /**
             * The payer's number — optional: the project's own customer
             * record carries it (ADR-0070 §1).
             *
             * @example "+237690000000"
             */
            'customer_phone_number' => ['nullable', 'string', 'max:32'],

            /**
             * The payer's name — optional, same rule.
             *
             * @example "ACME"
             */
            'customer_name' => ['nullable', 'string', 'max:255'],

            /**
             * The payer's email — optional, same rule.
             *
             * @example "contact@acme.test"
             */
            'customer_email' => ['nullable', 'email', 'max:255'],

            /**
             * The language of the payer's provider — optional, same
             * rule.
             *
             * @example "fr"
             */
            'customer_lang' => ['nullable', 'string', 'max:8'],

            /**
             * Where the payer comes back after paying — optional,
             * browser-side only (the server-to-server channel of the
             * `payment` module is its own `callback_url`).
             *
             * @example "https://acme.test/paiement/retour"
             */
            'return_url' => ['nullable', 'url'],
        ];
    }
}
