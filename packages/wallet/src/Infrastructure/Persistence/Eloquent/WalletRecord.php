<?php

declare(strict_types=1);

namespace Core\Wallet\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $project_id
 * @property string $party_id
 * @property string $currency
 * @property string $status
 * @property string $environment
 */
final class WalletRecord extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'wallets';

    protected $fillable = ['id', 'project_id', 'party_id', 'currency', 'status', 'environment'];
}
