<?php

declare(strict_types=1);

namespace Core\Identity\Infrastructure\Persistence\Eloquent;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property string $id
 * @property string $email
 * @property string $name
 * @property string $password_hash
 * @property string $role
 * @property string|null $mfa_secret_encrypted
 * @property bool $mfa_enabled
 * @property int|null $mfa_last_verified_timestep
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable $created_at
 */
final class OperatorRecord extends Model
{
    use HasApiTokens;
    use HasUuids;

    protected $table = 'operators';

    protected $fillable = ['id', 'email', 'name', 'password_hash', 'role', 'mfa_secret_encrypted', 'mfa_enabled', 'mfa_last_verified_timestep', 'revoked_at'];

    protected $casts = [
        'mfa_enabled' => 'bool',
        'revoked_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
    ];
}
