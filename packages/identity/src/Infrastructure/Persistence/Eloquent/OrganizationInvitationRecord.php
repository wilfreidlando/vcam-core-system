<?php

declare(strict_types=1);

namespace Core\Identity\Infrastructure\Persistence\Eloquent;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $email
 * @property string $role
 * @property string $token_hash
 * @property string $invited_by
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $accepted_at
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable $created_at
 */
final class OrganizationInvitationRecord extends Model
{
    use HasUuids;

    /**
     * The table has `created_at` (set from the domain's clock) and no
     * `updated_at`: Eloquent must not manage timestamps itself.
     */
    public $timestamps = false;

    protected $table = 'organization_invitations';

    protected $fillable = [
        'id',
        'organization_id',
        'email',
        'role',
        'token_hash',
        'invited_by',
        'expires_at',
        'accepted_at',
        'revoked_at',
        'created_at',
    ];

    protected $casts = [
        'expires_at' => 'immutable_datetime',
        'accepted_at' => 'immutable_datetime',
        'revoked_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
    ];
}
