<?php

declare(strict_types=1);

namespace Core\Identity\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property string $status
 */
final class ProjectRecord extends Model
{
    use HasUuids;

    protected $table = 'projects';

    protected $fillable = ['id', 'organization_id', 'name', 'status'];
}
