<?php

declare(strict_types=1);

namespace Core\Payment\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $project_id
 * @property string $channel
 * @property string $provider_code
 * @property string $idempotency_key
 * @property string $provider_transaction_ref
 * @property int $amount_minor
 * @property string $currency
 * @property string $status
 * @property bool $requires_action
 * @property string|null $action_hint
 * @property string|null $payment_url
 * @property string|null $error_message
 * @property string|null $return_url
 * @property string|null $wallet_recipient_external_ref
 */
final class PaymentRecord extends Model
{
    use HasUuids;

    protected $table = 'payments';

    protected $fillable = [
        'id',
        'project_id',
        'channel',
        'provider_code',
        'idempotency_key',
        'provider_transaction_ref',
        'amount_minor',
        'currency',
        'status',
        'requires_action',
        'action_hint',
        'payment_url',
        'error_message',
        'return_url',
        'wallet_recipient_external_ref',
    ];

    protected $casts = [
        'requires_action' => 'bool',
        'amount_minor' => 'int',
    ];
}
