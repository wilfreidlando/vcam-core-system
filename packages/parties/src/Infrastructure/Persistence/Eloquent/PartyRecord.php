<?php

declare(strict_types=1);

namespace Core\Parties\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $project_id
 * @property string $external_ref
 * @property string $type
 * @property string $kyc_status
 * @property array<string, mixed> $metadata
 * @property string $environment
 */
final class PartyRecord extends Model
{
    use HasUuids;

    protected $table = 'parties';

    protected $fillable = ['id', 'project_id', 'external_ref', 'type', 'kyc_status', 'metadata', 'environment'];

    protected $casts = [
        'metadata' => 'array',
    ];
}
