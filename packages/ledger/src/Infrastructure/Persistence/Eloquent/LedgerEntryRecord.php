<?php

declare(strict_types=1);

namespace Core\Ledger\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * No `HasUuids`: `id` is `bigserial` (ADR — sequential logs use bigint, see
 * the migration's docblock), not a UUID.
 *
 * @property int $id
 * @property string $transaction_id
 * @property string $account_id
 * @property string $direction
 * @property int $amount_minor
 * @property string $currency
 * @property \Carbon\CarbonImmutable $created_at
 */
final class LedgerEntryRecord extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'ledger_entries';

    protected $fillable = ['transaction_id', 'account_id', 'direction', 'amount_minor', 'currency'];
}
