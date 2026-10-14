<?php

declare(strict_types=1);

namespace Core\Wallet\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ListWalletTransactionsRequest extends FormRequest
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
             * The page to return, from 1.
             *
             * @example 1
             */
            'page' => ['nullable', 'integer', 'min:1'],
            /**
             * Movements per page, from 1 to 100.
             *
             * @example 25
             */
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
