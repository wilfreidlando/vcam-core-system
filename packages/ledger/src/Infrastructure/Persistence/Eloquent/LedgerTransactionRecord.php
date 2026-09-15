<?php

declare(strict_types=1);

namespace Core\Ledger\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $project_id
 * @property string $type
 * @property string|null $external_ref
 * @property string $idempotency_key
 */
final class LedgerTransactionRecord extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'ledger_transactions';

    protected $fillable = ['id', 'project_id', 'type', 'external_ref', 'idempotency_key'];

    /**
     * @return HasMany<LedgerEntryRecord, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntryRecord::class, 'transaction_id');
    }
}
