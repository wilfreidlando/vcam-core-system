<?php

declare(strict_types=1);

namespace Core\Identity\Infrastructure\Persistence\Eloquent;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $operator_id
 * @property string $role
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $revoked_at
 */
final class OrganizationMembershipRecord extends Model
{
    use HasUuids;

    /**
     * The table has `created_at` (set from the domain's clock) and no
     * `updated_at`: Eloquent must not manage timestamps itself.
     */
    public $timestamps = false;

    protected $table = 'organization_memberships';

    protected $fillable = ['id', 'organization_id', 'operator_id', 'role', 'created_at', 'revoked_at'];

    protected $casts = [
        'created_at' => 'immutable_datetime',
        'revoked_at' => 'immutable_datetime',
    ];
}
