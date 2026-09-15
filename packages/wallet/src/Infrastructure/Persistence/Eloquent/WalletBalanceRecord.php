<?php

declare(strict_types=1);

namespace Core\Wallet\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $wallet_id
 * @property int $available_minor
 * @property int $held_minor
 * @property int $version
 */
final class WalletBalanceRecord extends Model
{
    public const CREATED_AT = null;

    public $incrementing = false;

    protected $table = 'wallet_balances';

    protected $primaryKey = 'wallet_id';

    protected $keyType = 'string';

    protected $fillable = ['wallet_id', 'available_minor', 'held_minor', 'version'];
}
