<?php

declare(strict_types=1);

namespace Core\Payment\Infrastructure\Persistence\Eloquent;

use Core\Payment\Domain\Exception\PaymentAlreadyExists;
use Core\Payment\Domain\Model\Payment;
use Core\Payment\Domain\Port\PaymentRepository;
use Core\Payment\PaymentStatus;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class EloquentPaymentRepository implements PaymentRepository
{
    public function create(Payment $payment): void
    {
        try {
            // SAVEPOINT (nested transaction) — always runs inside
            // StartPaymentUseCase's outer transaction. Without this, a
            // caught violation poisons the whole outer PostgreSQL
            // transaction (see the identical comment on
            // EloquentWalletRepository::save()).
            DB::transaction(function () use ($payment): void {
                PaymentRecord::query()->create($this->toAttributes($payment));
            });
        } catch (UniqueConstraintViolationException) {
            throw new PaymentAlreadyExists;
        }
    }

    public function save(Payment $payment): void
    {
        PaymentRecord::query()->whereKey($payment->id)->update($this->toAttributes($payment));
    }

    public function find(string $id): ?Payment
    {
        $record = PaymentRecord::query()->find($id);

        return $record === null ? null : $this->toDomain($record);
    }

    public function findByIdempotencyKey(string $projectId, string $idempotencyKey): ?Payment
    {
        $record = PaymentRecord::query()
            ->where('project_id', $projectId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        return $record === null ? null : $this->toDomain($record);
    }

    public function findByProviderTransactionRef(string $providerCode, string $providerTransactionRef): ?Payment
    {
        $record = PaymentRecord::query()
            ->where('provider_code', $providerCode)
            ->where('provider_transaction_ref', $providerTransactionRef)
            ->first();

        return $record === null ? null : $this->toDomain($record);
    }

    /**
     * @return array<string, mixed>
     */
    private function toAttributes(Payment $payment): array
    {
        return [
            'id' => $payment->id,
            'project_id' => $payment->projectId,
            'channel' => $payment->channel,
            'provider_code' => $payment->providerCode,
            'idempotency_key' => $payment->idempotencyKey,
            'provider_transaction_ref' => $payment->providerTransactionRef,
            'amount_minor' => $payment->amount,
            'currency' => $payment->currency,
            'status' => $payment->status->value,
            'requires_action' => $payment->requiresAction,
            'action_hint' => $payment->actionHint,
            'payment_url' => $payment->paymentUrl,
            'error_message' => $payment->errorMessage,
            'return_url' => $payment->returnUrl,
            'wallet_recipient_external_ref' => $payment->walletRecipientExternalRef,
        ];
    }

    private function toDomain(PaymentRecord $record): Payment
    {
        return new Payment(
            id: $record->id,
            projectId: $record->project_id,
            channel: $record->channel,
            providerCode: $record->provider_code,
            idempotencyKey: $record->idempotency_key,
            providerTransactionRef: $record->provider_transaction_ref,
            amount: $record->amount_minor,
            currency: $record->currency,
            status: PaymentStatus::from($record->status),
            requiresAction: $record->requires_action,
            actionHint: $record->action_hint,
            paymentUrl: $record->payment_url,
            errorMessage: $record->error_message,
            returnUrl: $record->return_url,
            walletRecipientExternalRef: $record->wallet_recipient_external_ref,
        );
    }
}
