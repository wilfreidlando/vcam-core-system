<?php

declare(strict_types=1);

namespace Core\Payment\Infrastructure\Persistence\Eloquent;

use Core\Payment\Domain\Exception\PaymentAlreadyExists;
use Core\Payment\Domain\Model\Payment;
use Core\Payment\Domain\Port\PaymentRepository;
use Core\Payment\PaymentStatus;
use Core\Support\Environment;
use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Crypt;
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

    public function findByProviderTransactionRefForUpdate(string $providerCode, string $providerTransactionRef): ?Payment
    {
        // Must run inside the caller's open transaction (UnitOfWork) —
        // the lock is released at commit/rollback, not by this call.
        // `lockForUpdate()` is forwarded by Eloquent\Builder to the base
        // query builder rather than declared on it directly, which trips
        // the same Larastan false positive as findStuckSince()/
        // findByProject() above — same toBase()/hydrate() workaround.
        $base = PaymentRecord::query()
            ->where('provider_code', $providerCode)
            ->where('provider_transaction_ref', $providerTransactionRef)
            ->toBase();
        $base->lockForUpdate();

        $row = $base->first();

        if ($row === null) {
            return null;
        }

        $record = PaymentRecord::hydrate([(array) $row])->sole();

        return $this->toDomain($record);
    }

    public function findStuckSince(DateTimeImmutable $threshold, int $limit): array
    {
        // `whereIn`/`orderBy`/`take` are forwarded by Eloquent\Builder to
        // the underlying query builder rather than declared on it
        // directly; building the whole query on `toBase()` avoids a
        // Larastan false positive ("dynamic call to static method") on
        // those forwarded calls, and `PaymentRecord::hydrate()` restores
        // the model casts afterwards.
        $base = PaymentRecord::query()->toBase();
        $base->whereIn('status', [PaymentStatus::Pending->value, PaymentStatus::Processing->value]);
        $base->where('updated_at', '<', $threshold);
        $base->orderBy('updated_at');
        $base->take($limit);

        $rows = $base->get()->map(static fn (object $row): array => (array) $row)->all();

        $records = PaymentRecord::hydrate($rows);

        $payments = [];
        foreach ($records as $record) {
            $payments[] = $this->toDomain($record);
        }

        return $payments;
    }

    public function findByProject(?string $projectId, ?PaymentStatus $status, int $page, int $perPage, ?Environment $environment = null): array
    {
        // See findStuckSince() above for why this goes through toBase()/hydrate().
        $query = PaymentRecord::query();
        if ($projectId !== null) {
            $query->where('project_id', $projectId);
        }
        $base = $query->toBase();

        if ($status !== null) {
            $base->where('status', $status->value);
        }

        if ($environment !== null) {
            $base->where('environment', $environment->value);
        }

        $total = (clone $base)->count();

        // Tiebreaker on the primary key — see EloquentNotificationRepository's
        // identical comment: without it, rows sharing a created_at can
        // duplicate or vanish across pages.
        $base->orderBy('created_at', 'desc');
        $base->orderBy('id', 'desc');
        $base->skip(($page - 1) * $perPage);
        $base->take($perPage);

        $rows = $base->get()->map(static fn (object $row): array => (array) $row)->all();

        $records = PaymentRecord::hydrate($rows);

        $payments = [];
        foreach ($records as $record) {
            $payments[] = $this->toDomain($record);
        }

        return ['items' => $payments, 'total' => $total];
    }

    public function countStuckSince(DateTimeImmutable $threshold): int
    {
        // See findStuckSince() above for why whereIn()/count() go through toBase().
        $base = PaymentRecord::query()->where('updated_at', '<', $threshold)->toBase();
        $base->whereIn('status', [PaymentStatus::Pending->value, PaymentStatus::Processing->value]);

        return $base->count();
    }

    public function countRecentByStatus(DateTimeImmutable $since): array
    {
        $base = PaymentRecord::query()->where('created_at', '>=', $since)->toBase();

        $total = (clone $base)->count();
        $succeeded = (clone $base)->where('status', PaymentStatus::Succeeded->value)->count();

        return ['total' => $total, 'succeeded' => $succeeded];
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
            'callback_url' => $payment->callbackUrl,
            'callback_secret_encrypted' => $payment->callbackSecret === null ? null : Crypt::encryptString($payment->callbackSecret),
            'environment' => $payment->environment->value,
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
            callbackUrl: $record->callback_url,
            callbackSecret: $record->callback_secret_encrypted === null ? null : Crypt::decryptString($record->callback_secret_encrypted),
            // At runtime this is a mutable Illuminate\Support\Carbon, not
            // CarbonImmutable — confirmed by a real TypeError a direct
            // assignment triggered (ProviderApplicationRecord's identical
            // `@property \Carbon\CarbonImmutable` docblock elsewhere in
            // this module turned out to be inaccurate too, just never
            // exercised strictly enough to be caught). Domain stays
            // framework-free (G3b), so this is where the conversion has
            // to happen.
            updatedAt: DateTimeImmutable::createFromInterface($record->updated_at),
            environment: Environment::from($record->environment),
            createdAt: DateTimeImmutable::createFromInterface($record->created_at),
        );
    }
}
