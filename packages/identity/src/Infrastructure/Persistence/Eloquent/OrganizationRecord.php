<?php

declare(strict_types=1);

namespace Core\Identity\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $name
 * @property string $status
 */
final class OrganizationRecord extends Model
{
    use HasUuids;

    protected $table = 'organizations';

    protected $fillable = ['id', 'name', 'status'];
}
