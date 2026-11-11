<?php

declare(strict_types=1);

namespace Core\Billing\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * ADR-0122 §1 — la déclaration de forme d'un projet.
 *
 * `fields` porte la forme : une liste d'objets
 * `{key, label, type, required, max_length, source}` — des noms, jamais
 * des valeurs.
 *
 * @property string $id
 * @property string $project_id
 * @property int $version
 * @property string $status
 * @property string $unique_key
 * @property string $unique_key_pattern
 * @property string $fields the JSON **array** of the declared fields
 * @property \Illuminate\Support\Carbon|null $accepted_at
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
final class ProjectTenantSchemaRecord extends Model
{
    use HasUuids;

    protected $table = 'project_tenant_schemas';

    protected $fillable = [
        'id',
        'project_id',
        'version',
        'status',
        'unique_key',
        'unique_key_pattern',
        'fields',
        'accepted_at',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'version' => 'int',
        // L'instant de l'acceptation — une date, jamais une chaîne.
        'accepted_at' => 'datetime',
        // `fields` is deliberately NOT cast to `array`: the repository
        // serialises the list itself, the way `EloquentCustomerRepository`
        // does for `customers.metadata` — the cast would hide the exact
        // JSON written on the column.
    ];
}
