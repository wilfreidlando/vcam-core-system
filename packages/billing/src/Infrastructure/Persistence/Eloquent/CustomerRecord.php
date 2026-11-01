<?php

declare(strict_types=1);

namespace Core\Billing\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * ADR-0070 §1 — the customer of a project.
 *
 * The personal values are **never** stored in clear: `email_ciphertext`
 * and `phone_ciphertext` hold the ciphertext of ADR-0083 §2, and
 * `email_bidx` / `phone_bidx` hold their blind index — the searchable
 * form, and the form the uniqueness of an address is carried by.
 *
 * @property string $id
 * @property string $project_id
 * @property string $external_ref
 * @property string $display_name
 * @property string|null $email_ciphertext
 * @property string|null $email_bidx
 * @property string|null $phone_ciphertext
 * @property string|null $phone_bidx
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
        'email_ciphertext',
        'email_bidx',
        'phone_ciphertext',
        'phone_bidx',
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
