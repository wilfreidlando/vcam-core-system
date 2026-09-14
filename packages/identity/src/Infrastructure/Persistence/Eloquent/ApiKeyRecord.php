<?php

declare(strict_types=1);

namespace Core\Identity\Infrastructure\Persistence\Eloquent;

use Carbon\CarbonImmutable;
use Core\Support\Environment;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $project_id
 * @property Environment $environment
 * @property string $prefix
 * @property string $key_hash
 * @property list<string> $scopes
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable $created_at
 */
final class ApiKeyRecord extends Model
{
    use HasUuids;

    protected $table = 'api_keys';

    protected $fillable = ['id', 'project_id', 'environment', 'prefix', 'key_hash', 'scopes', 'revoked_at'];

    protected $casts = [
        'environment' => Environment::class,
        'scopes' => PostgresTextArrayCast::class,
        // CarbonImmutable extends \DateTimeImmutable directly, so the
        // repository can hand these straight to the Domain entity.
        'revoked_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
    ];
}
