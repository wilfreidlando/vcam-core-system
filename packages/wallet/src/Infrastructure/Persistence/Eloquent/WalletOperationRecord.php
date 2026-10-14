<?php

declare(strict_types=1);

namespace Core\Wallet\Infrastructure\Persistence\Eloquent;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $project_id
 * @property string $environment
 * @property string $type
 * @property string $idempotency_key
 * @property string $currency
 * @property int $total_minor
 * @property string|null $description
 * @property array<string, mixed> $metadata
 * @property list<array{from: string, to: string|null, amount_minor: int}> $legs
 * @property string|null $external_reference
 * @property string $ledger_transaction_id
 * @property CarbonImmutable $created_at
 */
final class WalletOperationRecord extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'wallet_operations';

    protected $fillable = [
        'id', 'project_id', 'environment', 'type', 'idempotency_key', 'currency', 'total_minor',
        'description', 'metadata', 'legs', 'external_reference', 'ledger_transaction_id', 'created_at',
    ];

    protected $casts = [
        'total_minor' => 'integer',
        'metadata' => 'array',
        'legs' => 'array',
        'created_at' => 'immutable_datetime',
    ];
}
