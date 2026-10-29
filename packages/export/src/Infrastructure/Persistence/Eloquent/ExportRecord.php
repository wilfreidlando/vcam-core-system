<?php

declare(strict_types=1);

namespace Core\Export\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $project_id
 * @property string $status
 * @property string $requested_by
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon|null $finished_at
 * @property \Illuminate\Support\Carbon $expires_at
 * @property string|null $file_path
 * @property string|null $error
 */
final class ExportRecord extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'exports';

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'id', 'project_id', 'status', 'requested_by', 'created_at', 'finished_at', 'expires_at', 'file_path', 'error',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }
}
