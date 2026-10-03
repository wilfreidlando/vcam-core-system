<?php

declare(strict_types=1);

namespace Core\Payment\Domain\Port;

use Core\Payment\Domain\Model\Payment;
use Core\Payment\PaymentStatus;
use DateTimeImmutable;

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
     * Persists a status transition on a Payment that already exists.
     * Race-safe only because the caller resolved the Payment via
     * `findByProviderTransactionRefForUpdate()` first, holding a row lock
     * for the whole transaction — this method itself performs a plain
     * unconditional `UPDATE`, with no status/version predicate of its own.
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

    /**
     * Same lookup as `findByProviderTransactionRef()`, but takes a
     * row-level lock (`SELECT ... FOR UPDATE`) for the remainder of the
     * caller's transaction. `HandlePaymentStatusChangedUseCase` is the
     * only writer of a Payment's status and is reached from two
     * concurrent entry points — an inbound webhook and the reconciliation
     * job (ADR-0044) — so without this lock two transactions can both
     * read the same pre-transition row, both pass the terminal-status
     * guard, and both write: a lost update, a double wallet credit
     * attempt, or an outbox event published twice for one transition.
     * The lock forces the second transaction to block until the first
     * commits, at which point it re-reads the now-committed row and its
     * terminal-status guard correctly short-circuits.
     */
    public function findByProviderTransactionRefForUpdate(string $providerCode, string $providerTransactionRef): ?Payment;

    /**
     * Non-terminal payments (`pending`/`processing`) untouched since
     * before `$threshold` — candidates for reconciliation (ADR-0044):
     * a missed webhook, not necessarily a failure.
     *
     * @return list<Payment>
     */
    public function findStuckSince(DateTimeImmutable $threshold, int $limit): array;

    /**
     * Newest first, optionally narrowed to one `projectId` and/or one
     * canonical `status` (ADR-0045 — the operator console's listing).
     * `$projectId` is nullable — a null value lists across every
     * Project, reachable only from the trusted operator-authenticated
     * admin plane (never from a Project/API-key caller, which has no
     * access to this port at all), same nullable-filter convention
     * already used by `ListProjectsCommand::$organizationId` and
     * `ListDeadWebhookDeliveriesCommand::$projectId`.
     *
     * @return array{items: list<Payment>, total: int}
     */
    public function findByProject(?string $projectId, ?PaymentStatus $status, int $page, int $perPage, ?\Core\Support\Environment $environment = null): array;

    /**
     * A pure count of the same candidate set `findStuckSince()` would
     * hydrate — for a dashboard stat that must never trigger a real
     * reconciliation run (review finding, Lot 6 follow-up: the admin
     * console used to call `ReconcileStuckPayments::reconcile()`, a
     * live-provider-calling, state-mutating action, just to populate a
     * "count" widget).
     */
    public function countStuckSince(DateTimeImmutable $threshold): int;

    /**
     * Global (cross-project) counts over a time window — the admin
     * dashboard's own read, distinct from `findByProject()`'s
     * project-scoped listing (ADR-0045 deliberately requires a
     * `projectId` there; this is the one admin-only exception, and it
     * stays a pure count, never row-level data).
     *
     * @return array{total: int, succeeded: int}
     */
    public function countRecentByStatus(DateTimeImmutable $since): array;
}
