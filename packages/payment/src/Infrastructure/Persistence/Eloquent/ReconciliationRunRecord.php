<?php

declare(strict_types=1);

namespace Core\Payment\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $project_id
 * @property string|null $provider_code
 * @property string $level
 * @property \Illuminate\Support\Carbon|null $window_start
 * @property \Illuminate\Support\Carbon|null $window_end
 * @property string $status
 * @property array<string, mixed> $stats
 * @property \Illuminate\Support\Carbon $started_at
 * @property \Illuminate\Support\Carbon|null $finished_at
 */
final class ReconciliationRunRecord extends Model
{
    public $incrementing = false;

    /** The table carries `started_at`/`finished_at`, not Eloquent's timestamps. */
    public $timestamps = false;

    protected $keyType = 'string';

    protected $table = 'reconciliation_runs';

    protected $fillable = [
        'id', 'project_id', 'provider_code', 'level', 'window_start', 'window_end',
        'status', 'stats', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'stats' => 'array',
        'window_start' => 'datetime',
        'window_end' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];
}
