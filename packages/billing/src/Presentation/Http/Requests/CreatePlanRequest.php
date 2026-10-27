<?php

declare(strict_types=1);

namespace Core\Billing\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /v1/plans` — ADR-0071 §5.
 *
 * The plan is created `draft`: it enters the catalogue only once a
 * version is published (ADR-0071 §2).
 */
final class CreatePlanRequest extends FormRequest
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
             * The stable identifier of the plan within the project. It
             * never changes, and no other plan of the project may carry
             * it. It is format-checked so it can never be injected into
             * a URL, a log or a cache key (ADR-0071 §1).
             *
             * @example "mensuel"
             */
            'code' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9_-]{0,63}$/'],
            /**
             * The name of the plan, as the project shows it.
             *
             * @example "Abonnement mensuel"
             */
            'name' => ['required', 'string', 'max:255'],
            /**
             * What the plan includes, in the words of the project.
             *
             * @example "Un utilisateur, un numéro de WhatsApp, un support par e-mail."
             */
            'description' => ['nullable', 'string', 'max:2000'],
            /**
             * `public` plans are the ones the project may sell; a
             * `private` plan is reserved to its own contracts.
             *
             * @example "public"
             */
            'visibility' => ['nullable', 'string', 'in:public,private'],
            /**
             * The order the plans are listed in — the lowest first.
             *
             * @example 10
             */
            'sort_order' => ['nullable', 'integer'],
        ];
    }
}
