<?php

declare(strict_types=1);

namespace Core\Billing\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * ADR-0071 §1 — a plan is stable; its **versions** carry the terms.
 *
 * Only the identity of the plan lives here: the code the project chose
 * and never changes, its name, its visibility and its rank in the
 * catalogue.
 *
 * @property string $id
 * @property string $project_id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property string $status
 * @property string $visibility
 * @property int $sort_order
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
final class PlanRecord extends Model
{
    use HasUuids;

    protected $table = 'plans';

    protected $fillable = [
        'id',
        'project_id',
        'code',
        'name',
        'description',
        'status',
        'visibility',
        'sort_order',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'sort_order' => 'int',
    ];
}
