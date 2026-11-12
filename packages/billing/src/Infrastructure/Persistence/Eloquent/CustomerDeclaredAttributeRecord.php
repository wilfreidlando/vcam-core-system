<?php

declare(strict_types=1);

namespace Core\Billing\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * ADR-0122 §5 — les valeurs déclarées d'un client.
 *
 * La table n'a pas de colonne `id` : la clé est le couple
 * `(customer_id, key)`, que l'index unique porte. Le modèle sert à lire ;
 * les écritures passent par le query builder, qui connaît la clé
 * composée (`uniqueIds()` est vidée sur le même modèle que
 * `BillingSettingsRecord`).
 *
 * Les valeurs sont en **clair** : ce sont des valeurs d'exploitation, ni
 * secrètes, ni cherchées, ni indexées (ADR-0122 §5).
 *
 * @property string $customer_id
 * @property string $project_id
 * @property string $key
 * @property string $value
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
final class CustomerDeclaredAttributeRecord extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $primaryKey = 'customer_id';

    protected $table = 'customer_declared_attributes';

    protected $fillable = [
        'customer_id',
        'project_id',
        'key',
        'value',
        'created_at',
        'updated_at',
    ];

    /**
     * There is no id to generate: the key of a row is the pair
     * `(customer_id, key)`.
     *
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return [];
    }
}
