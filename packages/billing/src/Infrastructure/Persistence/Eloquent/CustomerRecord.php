<?php

declare(strict_types=1);

namespace Core\Billing\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * ADR-0070 §1 — the customer of a project.
 *
 * The personal values are stored **in clear** (ADR-0123): `email` and
 * `phone` hold the address and the number, as the application that
 * declared them holds them. The Core keeps no key of its own for them;
 * what it still guards is their *exposure* (ADR-0083 §3) and their
 * *erasure* (ADR-0083 §6).
 *
 * @property string $id
 * @property string $project_id
 * @property string $external_ref
 * @property string $display_name
 * @property string|null $email
 * @property string|null $phone
 * @property string $locale
 * @property string $metadata the JSON **object** of the customer's metadata
 * @property string $status
 * @property int $version
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property \Illuminate\Support\Carbon|null $erased_at
 */
final class CustomerRecord extends Model
{
    use HasUuids;

    protected $table = 'customers';

    protected $fillable = [
        'id',
        'project_id',
        'external_ref',
        'display_name',
        'email',
        'phone',
        'locale',
        'metadata',
        'status',
        'version',
        'erased_at',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'version' => 'int',
        // The instant of the erasure — a date, never a string.
        'erased_at' => 'datetime',
        // `metadata` is deliberately NOT cast to `array`: the column is a
        // jsonb **object** (`customers_metadata_object_check`), and the
        // cast would encode an empty map as `[]` — which the column
        // refuses. The repository serialises it as an object instead.
    ];
}
