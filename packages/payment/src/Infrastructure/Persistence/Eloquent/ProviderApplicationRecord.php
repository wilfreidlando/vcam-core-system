<?php

declare(strict_types=1);

namespace Core\Payment\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $project_id
 * @property string $provider_code
 * @property string $public_key
 * @property string $private_key_encrypted
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 */
final class ProviderApplicationRecord extends Model
{
    use HasUuids;

    protected $table = 'payment_provider_applications';

    protected $fillable = ['id', 'project_id', 'provider_code', 'public_key', 'private_key_encrypted'];
}
