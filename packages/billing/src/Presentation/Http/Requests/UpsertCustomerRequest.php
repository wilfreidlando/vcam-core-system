<?php

declare(strict_types=1);

namespace Core\Billing\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `PUT /v1/customers/{externalRef}` — ADR-0070 §2.
 *
 * The customer is **declared by the project**: the reference the project
 * knows its customer by always lands on the same customer.
 */
final class UpsertCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The scope is checked by the controller (`customers:write`): the
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
             * The name the project shows for this customer. It is a
             * business name, not a personal one: the personal values are
             * the contact below. It carries no control character
             * (ADR-0070 §Sécurité) — a name is displayed, logged and
             * compared, and a hidden control character in it is a trap.
             *
             * @example "Marie Ngo Bell"
             */
            'display_name' => ['required', 'string', 'max:200', 'not_regex:/[\x00-\x1F\x7F]/'],
            /**
             * The contact address of the customer. It is **personal
             * data**: it is stored in clear by the Core (ADR-0123) and never used as a
             * search key (ADR-0083 §2). The same address cannot be
             * declared twice for the same project.
             *
             * @example "marie@example.cm"
             */
            'email' => ['nullable', 'string', 'email', 'max:254'],
            /**
             * The contact number of the customer, in E.164. It is
             * personal data too (ADR-0083 §2).
             *
             * @example "+237699887766"
             */
            'phone' => ['nullable', 'string', 'regex:/^\+[1-9][0-9]{7,14}$/'],
            /**
             * The locale of the customer, used to format the dates and
             * the amounts shown to them. Defaults to `fr`.
             *
             * @example "fr"
             */
            'locale' => ['nullable', 'string', 'max:16'],
            /**
             * Technical identifiers of the project about the customer
             * (a segment, a school, a tenant). **Never personal data**
             * (ADR-0070 §1): at most 16 keys and 4 KiB, the same
             * ceiling the database enforces.
             *
             * @example {"segment": "retail"}
             */
            'metadata' => ['nullable', 'array'],
            'metadata.*' => ['string', 'max:255'],
        ];
    }
}
