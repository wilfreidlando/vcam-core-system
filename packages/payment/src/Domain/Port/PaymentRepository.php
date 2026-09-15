<?php

declare(strict_types=1);

namespace Core\Payment\Domain\Port;

use Core\Payment\Domain\Model\Payment;

interface PaymentRepository
{
    /**
     * Persists a brand new Payment.
     *
     * @throws \Core\Payment\Domain\Exception\PaymentAlreadyExists on a race
     *                                                             against the `(project_id, idempotency_key)` unique constraint.
     */
    public function create(Payment $payment): void;

    /**
     * Persists a status transition on a Payment that already exists — the
     * caller always has it via `find()`/`findByProviderTransactionRef()`
     * first, so no race handling is needed here (unlike `create()`).
     */
    public function save(Payment $payment): void;

    public function find(string $id): ?Payment;

    public function findByIdempotencyKey(string $projectId, string $idempotencyKey): ?Payment;

    /**
     * Used by inbound webhook handling (ADR-0038 §4/§5): a payment is
     * pinned to exactly one provider at creation, so this lookup is
     * always direct — never a search across every registered adapter.
     */
    public function findByProviderTransactionRef(string $providerCode, string $providerTransactionRef): ?Payment;
}
