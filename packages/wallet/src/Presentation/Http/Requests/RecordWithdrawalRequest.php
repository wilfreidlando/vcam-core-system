<?php

declare(strict_types=1);

namespace Core\Wallet\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates `POST /v1/wallets/withdrawals` (ADR-0065).
 */
final class RecordWithdrawalRequest extends FormRequest
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
             * Your own reference of the party whose wallet is debited.
             *
             * @example "merchant:01J9Z3K7Q5"
             */
            'party' => ['required', 'string', 'max:191'],
            /**
             * The amount that left, an integer in the currency's smallest
             * unit.
             *
             * @example 120000
             */
            'amount' => ['required', 'integer', 'min:1'],
            /**
             * The ISO 4217 code of the amount.
             *
             * @example "XAF"
             */
            'currency' => ['required', 'string', 'size:3'],
            /**
             * A label returned in the wallet's history.
             *
             * @example "Withdrawal to MTN Mobile Money"
             */
            'description' => ['nullable', 'string', 'max:255'],
            /**
             * The proof of the payout you made outside the Core (the mobile
             * money transaction id, a bank wire reference…).
             *
             * @example "MP241014.1532.C12345"
             */
            'external_reference' => ['nullable', 'string', 'max:255'],
            /**
             * Any JSON object of yours (4 KB at most once encoded), opaque to
             * the Core.
             *
             * @example {"request": "WD-2026-0042"}
             */
            'metadata' => ['nullable', 'array'],
        ];
    }
}
