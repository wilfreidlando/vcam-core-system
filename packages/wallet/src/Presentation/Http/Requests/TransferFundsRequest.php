<?php

declare(strict_types=1);

namespace Core\Wallet\Presentation\Http\Requests;

use Core\Wallet\Application\UseCase\TransferFundsUseCase;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates `POST /v1/wallets/transfers` (ADR-0065). The `wallets:write`
 * scope is enforced in the controller; `Idempotency-Key` by the route's
 * `idempotency` middleware (G7).
 */
final class TransferFundsRequest extends FormRequest
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
             * The ISO 4217 code of every leg's amount. One transfer moves a
             * single currency.
             *
             * @example "XAF"
             */
            'currency' => ['required', 'string', 'size:3'],
            /**
             * Between 1 and 20 movements, applied together in one ledger
             * transaction: every leg lands, or none does.
             */
            'legs' => ['required', 'array', 'min:1', 'max:'.TransferFundsUseCase::MAX_LEGS],
            /**
             * Your own reference of the party whose wallet is debited. It
             * must already hold a wallet in `currency` with enough
             * available funds.
             *
             * @example "escrow"
             */
            'legs.*.from' => ['required', 'string', 'max:191'],
            /**
             * Your own reference of the party whose wallet is credited. Its
             * wallet is created on first credit.
             *
             * @example "merchant:01J9Z3K7Q5"
             */
            'legs.*.to' => ['required', 'string', 'max:191', 'different:legs.*.from'],
            /**
             * The amount moved by this leg, an integer in the currency's
             * smallest unit (`XAF` has none: `45000` is 45,000 XAF).
             *
             * @example 45000
             */
            'legs.*.amount' => ['required', 'integer', 'min:1'],
            /**
             * The type given to the destination party if it does not exist
             * yet (lowercase, underscores). Ignored for an existing party.
             *
             * @example "merchant"
             */
            'legs.*.to_party_type' => ['nullable', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/'],
            /**
             * A label for this transfer, returned in each wallet's history.
             *
             * @example "Settlement of booking ETR-7K3Q9"
             */
            'description' => ['nullable', 'string', 'max:255'],
            /**
             * Any JSON object of yours (4 KB at most once encoded), opaque to
             * the Core and returned in each wallet's history.
             *
             * @example {"order": "ETR-7K3Q9"}
             */
            'metadata' => ['nullable', 'array'],
        ];
    }
}
