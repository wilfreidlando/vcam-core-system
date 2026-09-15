<?php

declare(strict_types=1);

namespace Core\Ledger\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $project_id
 * @property string $kind
 * @property string $reference
 * @property string $currency
 */
final class LedgerAccountRecord extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'ledger_accounts';

    protected $fillable = ['id', 'project_id', 'kind', 'reference', 'currency'];
}
