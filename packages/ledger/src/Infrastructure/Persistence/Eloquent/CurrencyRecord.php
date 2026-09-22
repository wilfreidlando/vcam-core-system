<?php

declare(strict_types=1);

namespace Core\Ledger\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $code
 * @property int $exponent
 */
final class CurrencyRecord extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $table = 'currencies';

    protected $primaryKey = 'code';

    protected $fillable = ['code', 'exponent'];
}
